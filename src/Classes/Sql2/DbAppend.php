<?php

declare(strict_types=1);

namespace Glued\Lib\Classes\Sql2;

use PDO;
use Ramsey\Uuid\Uuid;
use Rs\Json\Merge\Patch as JsonMergePatch;


/**
 * Logged documents repository (append-only versions).
 *
 * Table contract (logged_doc):
 * - PK(version), uuid, doc, meta, iat, sat, dat?, virtual period
 */
final class DbAppend extends Base
{
    public function __construct(PDO $pdo, string $table, ?string $schema = 'glued')
    {
        parent::__construct($pdo, $table, $schema);
    }

    /**
     * Append a new version. Duplicate content for the same uuid is ignored.
     *
     * @return string Version UUID (existing latest if duplicate content, otherwise new)
     */
    public function append(array|object $doc, array|object $meta = [], ?string $sat = null): string
    {
        $uuid = (string)((is_array($doc) ? ($doc['uuid'] ?? null) : ($doc->uuid ?? null)) ?? Uuid::uuid4());
        [$d, $m]  = $this->normalize($doc, $meta, $uuid);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        $this->query = "
    WITH candidate AS (
      SELECT
        :uuid::uuid                                        AS uuid,
        :doc::jsonb                                        AS doc,
        :meta::jsonb                                       AS meta,
        :sat                                               AS sat,
        (EXTRACT(EPOCH FROM clock_timestamp())*1000)::bigint AS iat,
        decode(md5((:doc::jsonb - 'uuid')::text), 'hex')     AS nonce_calc
    ),
    ins AS (
      INSERT INTO {$this->schema}.{$this->table} (uuid, doc, meta, iat, sat)
      SELECT c.uuid, c.doc, c.meta, c.iat, c.sat
        FROM candidate c
       WHERE COALESCE(
               (SELECT l.nonce
                  FROM {$this->schema}.{$this->table} l
                 WHERE l.{$this->uuidCol} = c.uuid
                 ORDER BY l.iat DESC, {$this->versionCol} DESC
                 LIMIT 1),
               '\x'::bytea
             ) IS DISTINCT FROM c.nonce_calc
      RETURNING {$this->versionCol} AS version, iat, encode(nonce, 'hex') AS nonce
    )
    SELECT :uuid::uuid AS uuid, version, iat, nonce
      FROM ins
    UNION ALL
    SELECT :uuid::uuid AS uuid,
           t.{$this->versionCol} AS version,
           t.iat,
           encode(t.nonce, 'hex') AS nonce
      FROM {$this->schema}.{$this->table} t
     WHERE t.{$this->uuidCol} = :uuid
       AND NOT EXISTS (SELECT 1 FROM ins)
    ORDER BY iat DESC, version DESC
    LIMIT 1;
    ";

        $this->params = [
            ':uuid' => $uuid,
            ':doc'  => $docJson,
            ':meta' => $metaJson,
            ':sat'  => $sat,
        ];

        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) { $this->stmt->bindValue($k, $v); }
        $this->stmt->execute();

        /** @var array{uuid:string,version:string,iat:string,nonce:string} $row */
        $row = (array)$this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();

        // Fallback (shouldn't happen, but keep shape)
        return $row ?: ['uuid' => $uuid, 'version' => '', 'iat' => '', 'nonce' => ''];
    }


    /** Latest version envelope by uuid. */
    public function latest(string $uuid): ?array
    {
        return $this->get($uuid);
    }

    /** Fetch a specific version envelope by version UUID. */
    public function byVersion(string $version): ?array
    {
        $this->query = "
            SELECT {$this->selectEnvelope()}
              FROM {$this->schema}.{$this->table}
             WHERE {$this->versionCol} = :v
             LIMIT 1
        ";
        $this->params = [':v' => $version];
        $this->stmt = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':v', $version);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();
        return $row ? json_decode($row, true) : null;
    }
}
