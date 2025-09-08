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
 * Table contract (external_ingest_log):
 * - PK (uuid, version), uuid stable v5(ext_id), UNIQUE (uuid, nonce)
 */
final class IngestVersioned extends Base
{
    public function __construct(PDO $pdo, string $table, ?string $schema = 'glued')
    {
        parent::__construct($pdo, $table, $schema);
    }

    /**
     * Append a versioned ingest row. Duplicate content (same uuid, nonce) is ignored.
     *
     * @param array|object $doc
     * @param string $extId
     * @param string $sourceName Namespacing input for the v5 stable uuid
     * @param array|object $meta
     * @param string|null $sat
     * @return array{uuid:string,version:string,iat:string}|null  null if duplicate content
     */
    public function log(array|object $doc, string $extId, string $sourceName, array|object $meta = [], ?string $sat = null): ?array
    {
        [$d, $m] = $this->normalize($doc, $meta);
        $docJson = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);
        $stable = UuidTools::stableForSource($this->table, $sourceName, $extId);

        $this->query = "
        INSERT INTO {$this->schema}.{$this->table} (ext_id, uuid, doc, meta, sat, iat)
        VALUES (:ext, :uuid::uuid, :doc::jsonb, :meta::jsonb, :sat, now())
        ON CONFLICT (uuid, nonce) DO NOTHING
        RETURNING uuid, version, iat
        ";
        $this->params = [
            ':ext' => $extId,
            ':uuid' => $stable,
            ':doc' => $docJson,
            ':meta' => $metaJson,
            ':sat' => $sat,
        ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();

        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();
        if ($row) return (array)$row;

        // Duplicate content: fetch latest for this stream
        $this->query = "SELECT uuid, version, iat FROM {$this->schema}.{$this->table} WHERE ext_id = :ext ORDER BY iat DESC LIMIT 1";
        $this->params = [':ext' => $extId];
        $this->stmt = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':ext', $extId);
        $this->stmt->execute();
        $existing = $this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();
        return $existing ? (array)$existing : null;
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
        $updateMutable = $options['updateMutable'] ?? true;
        $linkMeta = $options['linkMeta'] ?? [];
        $onDuplicateRaw = $options['onDuplicateRaw'] ?? 'skip'; // 'skip' or 'transform'

        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            // 1) RAW insert
            $rawRow = $this->log($rawDoc, $extId, $rawMeta, $sat); // may return null if your log() ever dedupes; as written it always inserts
            $extUuid = UuidTools::stableForSource('external_ingest_log', $sourceName, $extId);

            if ($rawRow === null && $onDuplicateRaw === 'skip') {
                $pdo->commit();
                return ['raw' => null, 'internal' => []];
            }

            // 2) transform RAW → INTERNAL (1→N allowed)
            $rawDocArr = (array)$rawDoc;
            $rawMetaArr = (array)$rawMeta;
            $items = is_callable($transform)
                ? $transform($rawDocArr, $rawMetaArr, $extId)
                : $transform->transform($rawDocArr, $rawMetaArr, $extId);

            $logged = new LoggedRepo($pdo);
            $mutable = new MutableRepo($pdo);

            $out = [];
            foreach ($items as $it) {
                $intDoc = (array)($it['doc'] ?? []);
                $intMeta = (array)($it['meta'] ?? []);
                $intSat = $it['sat'] ?? $sat;
                $intUuid = (string)($it['uuid'] ?? ($intDoc['uuid'] ?? Uuid::uuid4()->toString()));
                $intDoc['uuid'] = $intUuid;

                // 3a) Append internal version (dedup by (uuid, nonce))
                $ver = $logged->append($intDoc, $intMeta, $intSat);

                // 3b) Upsert mutable + log on change (optional)
                if ($updateMutable) {
                    $mutable->upsertWithLog($intDoc, $intMeta, $intSat);
                }

                // 4) Link RAW ↔ INTERNAL
                $stmt = $pdo->prepare("
                  INSERT INTO glued.ext_int_map (ext_uuid, ext_id, source, int_uuid, meta, iat, uat)
                  VALUES (:ext_uuid::uuid, :ext_id, :source, :int_uuid::uuid, :meta::jsonb, now(), now())
                  ON CONFLICT (ext_uuid, int_uuid) DO UPDATE
                    SET meta = COALESCE(:meta::jsonb, ext_int_map.meta),
                        uat  = now()
                ");
                $stmt->bindValue(':ext_uuid', $extUuid);
                $stmt->bindValue(':ext_id', $extId);
                $stmt->bindValue(':source', $sourceName);
                $stmt->bindValue(':int_uuid', $intUuid);
                $stmt->bindValue(':meta', json_encode($linkMeta, $this->jsonFlags));
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
