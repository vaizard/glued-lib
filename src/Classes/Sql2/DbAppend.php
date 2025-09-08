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
 * - UNIQUE (uuid, nonce) for dedupe
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
        $uuid = (string)($doc['uuid'] ?? Uuid::uuid4());
        [$d, $m] = $this->normalize($doc, $meta, $uuid);
        $docJson = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        $this->query = "
        INSERT INTO {$this->schema}.{$this->table} (uuid, doc, meta, iat, sat)
        VALUES (:uuid::uuid, :doc::jsonb, :meta::jsonb, now(), :sat)
        ON CONFLICT (uuid, nonce) DO NOTHING
        RETURNING version
        ";
        $this->params = [
            ':uuid' => $uuid,
            ':doc' => $docJson,
            ':meta' => $metaJson,
            ':sat' => $sat,
        ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $ver = $this->stmt->fetchColumn();
        $this->reset();

        if ($ver) return (string)$ver;

        // Duplicate: return latest version id.
        $this->query = "SELECT version FROM {$this->schema}.{$this->table} WHERE {$this->uuidCol} = :u ORDER BY iat DESC LIMIT 1";
        $this->params = [':u' => $uuid];
        $this->stmt = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':u', $uuid);
        $this->stmt->execute();
        $ret = (string)$this->stmt->fetchColumn();
        $this->reset();
        return $ret;
    }

    /** Latest version envelope by uuid. */
    public function latest(string $uuid): ?array
    {
        $this->query = "SELECT {$this->selectEnvelope()} FROM {$this->schema}.{$this->table} WHERE {$this->uuidCol} = :u ORDER BY iat DESC LIMIT 1";
        $this->params = [':u' => $uuid];
        $this->stmt = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':u', $uuid);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();
        return $row ? json_decode($row, true) : null;
    }

    /** Fetch a specific version envelope by version UUID. */
    public function byVersion(string $version): ?array
    {
        $this->query = "SELECT {$this->selectEnvelope()} FROM {$this->schema}.{$this->table} WHERE version = :v";
        $this->params = [':v' => $version];
        $this->stmt = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':v', $version);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();
        return $row ? json_decode($row, true) : null;
    }
}


