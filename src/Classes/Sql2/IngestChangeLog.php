<?php
declare(strict_types=1);
namespace Glued\Lib\Classes\Sql2;

use \PDO;
use Ramsey\Uuid\Uuid;
use Rs\Json\Merge\Patch as JsonMergePatch;


/**
 * Return one or more internal records for a given raw doc+meta.
 * Each item must contain 'doc' (array), and may contain 'meta', 'uuid', 'sat'.
 *
 * @return iterable<int, array{doc:array, meta?:array, uuid?:string, sat?:?string}>
 */
interface TransformerInterface
{
    public function transform(array $rawDoc, array $rawMeta, ?string $extId = null): iterable;
}


/**
 * External ingest (append-only, stable uuid per ext_id via UUID v5).
 *
 * Table contract (ingest_changelog):
 * - PK (uuid, version), stable uuid v5(ext_id), NON-UNIQUE (uuid, nonce)  ← consecutive dedupe in SQL
 * - iat/uat: bigint ms since epoch
 * - period: STORED, clamped
 */
final class IngestChangeLog extends Base
{
    public function __construct(PDO $pdo, string $table, ?string $schema = null)
    {
        parent::__construct($pdo, $table, $schema);
    }

    /**
     * Append a versioned ingest row.
     * Consecutive dedupe: if the incoming doc has the same nonce as the last version for this uuid,
     * returns null; otherwise inserts and returns {uuid,version,iat}.
     *
     * @return array{uuid:string,version:string,iat:string}|null
     */
    /**
     * Append a versioned ingest row (consecutive dedupe, no advisory lock).
     * Returns {uuid, version, iat, nonce}. If duplicate, falls back to latest row.
     *
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function appendIfChanged(array|object $doc, string $extId, string $sourceName, array|object $meta = [], ?string $sat = null): array
    {
        [$d, $m]  = $this->normalize($doc, $meta);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);
        $stable   = UuidTools::stableForSource($this->table, $sourceName, $extId);

        $this->query = "
    WITH candidate AS (
      SELECT
        :uuid::uuid                                        AS uuid,
        :ext                                               AS ext_id,
        :doc::jsonb                                        AS doc,
        :meta::jsonb                                       AS meta,
        :sat                                               AS sat,
        (EXTRACT(EPOCH FROM clock_timestamp())*1000)::bigint AS iat,
        decode(md5((:doc::jsonb)::text), 'hex')            AS nonce_calc
    ),
    last AS (
      SELECT l.nonce
        FROM {$this->schema}.{$this->table} l
       WHERE l.{$this->uuidCol} = :uuid
       ORDER BY l.iat DESC, {$this->versionCol} DESC
       LIMIT 1
    ),
    ins AS (
      INSERT INTO {$this->schema}.{$this->table} (ext_id, uuid, doc, meta, iat, sat)
      SELECT c.ext_id, c.uuid, c.doc, c.meta, c.iat, c.sat
        FROM candidate c
       WHERE COALESCE((SELECT nonce FROM last), '\x'::bytea) IS DISTINCT FROM c.nonce_calc
      RETURNING
        {$this->uuidCol}    AS uuid,
        {$this->versionCol} AS version,
        iat,
        encode(nonce, 'hex') AS nonce
    )
    SELECT uuid, version, iat, nonce
      FROM ins
    UNION ALL
    SELECT t.{$this->uuidCol}    AS uuid,
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
            ':ext'  => $extId,
            ':uuid' => $stable,
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

        return $row ?: ['uuid' => $stable, 'version' => '', 'iat' => '', 'nonce' => ''];
    }



    /**
     * Ingest a RAW row, transform to INTERNAL, append to logged_doc, upsert into mutable_doc,
     * and link raw↔internal in ext_int_map. Idempotent on all steps.
     *
     * @param array|object $rawDoc
     * @param string $extId
     * @param string $sourceName Used for stable ext_uuid v5 namespace
     * @param array|object $rawMeta
     * @param string|null $sat
     * @param TransformerInterface|callable $transform fn(array $rawDoc, array $rawMeta, ?string $extId): iterable<array{doc:array, meta?:array, uuid?:string, sat?:?string}>
     * @param array $options ['updateMutable'=>true, 'linkMeta'=>[] , 'onDuplicateRaw'=>'skip'|'transform']
     *
     * @return array{
     *   raw: array{uuid:string,version:string,iat:string}|null,
     *   internal: list<array{uuid:string, version:string|null}>
     * }
     */
    public function transformToInternal(
        array|object                  $rawDoc,
        string                        $extId,
        string                        $sourceName,
        array|object                  $rawMeta = [],
        ?string                       $sat = null,
        TransformerInterface|callable $transform,
        array                         $options = []
    ): array {
        $updateMutable  = $options['updateMutable']  ?? true;
        $linkMeta       = $options['linkMeta']       ?? [];
        $onDuplicateRaw = $options['onDuplicateRaw'] ?? 'skip'; // 'skip' or 'transform'

        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            // 1) RAW insert (versioned stream)
            $rawRow  = $this->log($rawDoc, $extId, $sourceName, $rawMeta, $sat); // null if consecutive duplicate
            $extUuid = UuidTools::stableForSource($this->table, $sourceName, $extId);

            if ($rawRow === null && $onDuplicateRaw === 'skip') {
                $pdo->commit();
                return ['raw' => null, 'internal' => []];
            }

            // 2) transform RAW → INTERNAL (1→N)
            $rawDocArr  = (array)$rawDoc;
            $rawMetaArr = (array)$rawMeta;
            $items = is_callable($transform)
                ? $transform($rawDocArr, $rawMetaArr, $extId)
                : $transform->transform($rawDocArr, $rawMetaArr, $extId);

            $logged  = new LoggedRepo($pdo);
            $mutable = new MutableRepo($pdo);

            $out = [];
            foreach ($items as $it) {
                $intDoc  = (array)($it['doc'] ?? []);
                $intMeta = (array)($it['meta'] ?? []);
                $intSat  = $it['sat'] ?? $sat;
                $intUuid = (string)($it['uuid'] ?? ($intDoc['uuid'] ?? Uuid::uuid4()->toString()));
                $intDoc['uuid'] = $intUuid;

                // 3a) Append internal version (consecutive dedupe in LoggedRepo)
                $ver = $logged->append($intDoc, $intMeta, $intSat);

                // 3b) Upsert mutable + log on change (optional)
                if ($updateMutable) {
                    $mutable->upsertWithLog($intDoc, $intMeta, $intSat);
                }

                // 4) Link RAW ↔ INTERNAL (ms timestamps)
                $stmt = $pdo->prepare("
                  WITH ts AS (SELECT (EXTRACT(EPOCH FROM clock_timestamp())*1000)::bigint AS ms)
                  INSERT INTO glued.ext_int_map (ext_uuid, ext_id, source, int_uuid, meta, iat, uat)
                  SELECT :ext_uuid::uuid, :ext_id, :source, :int_uuid::uuid, :meta::jsonb, ts.ms, ts.ms FROM ts
                  ON CONFLICT (ext_uuid, int_uuid) DO UPDATE
                    SET meta = COALESCE(:meta::jsonb, ext_int_map.meta),
                        uat  = (EXTRACT(EPOCH FROM clock_timestamp())*1000)::bigint
                ");
                $stmt->bindValue(':ext_uuid', $extUuid);
                $stmt->bindValue(':ext_id',   $extId);
                $stmt->bindValue(':source',   $sourceName);
                $stmt->bindValue(':int_uuid', $intUuid);
                $stmt->bindValue(':meta',     json_encode($linkMeta, $this->jsonFlags));
                $stmt->execute();

                $out[] = ['uuid' => $intUuid, 'version' => $ver];
            }

            $pdo->commit();
            return ['raw' => $rawRow, 'internal' => $out];

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
