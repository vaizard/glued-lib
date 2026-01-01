<?php
declare(strict_types=1);

namespace Glued\Lib\Classes\Sql2;

use PDO;

/**
 * Return one or more internal records for a given raw doc+meta.
 *
 * Each yielded item MUST contain 'doc' (array).
 * It MAY contain:
 *   - 'uuid' (string): internal UUID (stable strongly preferred)
 *   - 'kind' (string) + 'key' (string): used to derive stable internal UUID if 'uuid' missing
 *   - 'meta' (array)
 *   - 'sat' (?string)
 *   - 'stream' (string): overrides default upstream stream for provenance
 *
 * @return iterable<int, array{
 *   doc: array,
 *   meta?: array,
 *   uuid?: string,
 *   kind?: string,
 *   key?: string,
 *   sat?: ?string,
 *   stream?: string
 * }>
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
 * - period: generated, clamped
 *
 * ****************************************
 * Identity & idempotency rules (important)
 * ****************************************
 *
 * External ingest (ext_uuid/ext_version) and internal normalized docs (int_uuid/int_version)
 * MUST NOT share identifiers by default.
 *
 * Why:
 * - Transform is 1→N and/or N→1. If we reuse ext_uuid as int_uuid, 1→N immediately collides.
 * - Internal documents may have multiple upstream inputs (joins), multiple sources, or internal edits.
 * - Internal version IDs represent accepted internal snapshots; they cannot be the same as upstream
 *   version IDs once transforms, merges, or internal edits exist.
 *
 * Correct approach:
 * - Internal uuid SHOULD be stable (idempotent) but derived using a discriminator:
 *     int_uuid = stable_uuid_v5(ns, ext_uuid + ":" + entityKind + ":" + entityKey)
 *   so 1→N becomes stable and collision-free.
 * - Internal version SHOULD be append-only UUIDv7 (or equivalent) keyed to "internal state changed",
 *   independent from upstream cadence.
 *
 * Provenance:
 * - Store upstream provenance in internal meta (ext_uuid, ext_version, stream, sat, transformer version),
 *   so we can audit "what input created this internal state" without coupling identifiers.
 */
final class IngestChangeLog extends Base
{
    public function __construct(PDO $pdo, string $table, ?string $schema = null)
    {
        parent::__construct($pdo, $table, $schema);
    }
    

    /*
     * $raw = $ingest->appendIfChanged($doc, $extId, $ifName, $meta, $sat, stream: 'acord.packset');
     */

    /**
     * Append a versioned ingest row (consecutive dedupe, with per-uuid xact advisory lock).
     *
     * IMPORTANT: extUuid stability must include upstream "stream" (entity/table) to avoid collisions
     * when different upstream streams reuse the same extId inside the same ingest_changelog table.
     *
     * NOTE: We force doc.uuid := extUuid to avoid volatile upstream 'uuid' fields causing nonce churn.
     *
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function appendIfChanged(
        array|object $doc,
        string $extId,
        string $ifName,
        array|object $meta = [],
        ?string $sat = null,
        ?string $stream = null
    ): array {

        // Fold stream into the namespace key used for stable UUIDv5, force it to doc.uuid (prevents upstream random uuid fields from breaking dedupe).
        $stream    = $stream !== null ? trim($stream) : '';
        $sourceKey = $stream !== '' ? ($ifName . ':' . $stream) : $ifName;
        $stable = UuidTools::stableForSource($this->table, $sourceKey, $extId);
        [$uuid, $docJson, $metaJson] = $this->normalizeToJson($doc, $meta, $stable);

        $this->query = "
        WITH lock AS (
          SELECT pg_advisory_xact_lock(hashtextextended(:uuid::text, 0)) AS ok
        ),
        candidate AS (
          SELECT
            :uuid::uuid                                         AS uuid,
            :ext                                                AS ext_id,
            :doc::jsonb                                         AS doc,
            :meta::jsonb                                        AS meta,
            :sat                                                AS sat,
            (EXTRACT(EPOCH FROM clock_timestamp())*1000)::bigint AS iat,
            decode(md5((:doc::jsonb - 'uuid')::text), 'hex')     AS nonce_calc
          FROM lock
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
           WHERE COALESCE((SELECT nonce FROM last), '\\x'::bytea) IS DISTINCT FROM c.nonce_calc
          RETURNING
            {$this->uuidCol}    AS uuid,
            {$this->versionCol} AS version,
            iat,
            encode(nonce, 'hex') AS nonce
        )
        SELECT uuid, version, iat, nonce
          FROM ins
        UNION ALL
        SELECT t.{$this->uuidCol}     AS uuid,
               t.{$this->versionCol}  AS version,
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
            ':uuid' => $uuid,
            ':doc'  => $docJson,
            ':meta' => $metaJson,
            ':sat'  => $sat,
        ];

        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) {
            $this->stmt->bindValue($k, $v);
        }
        $this->stmt->execute();

        /** @var array{uuid:string,version:string,iat:string,nonce:string} $row */
        $row = (array)$this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();

        return $row ?: ['uuid' => $uuid, 'version' => '', 'iat' => '', 'nonce' => ''];
    }

    /**
     * Ingest RAW, transform to INTERNAL, append to logged_doc, upsert into mutable_doc.
     *
     * Options:
     *  - ifUuid (string, REQUIRED): our UUID for the interface config
     *  - ifInstance (string, optional): remote-defined instance/tenant id (string)
     *  - stream (string, REQUIRED): default upstream stream name (e.g. "acord.packset")
     *  - rawStream (string, optional): upstream stream name for RAW ingest uuid namespacing
     *  - schemaVer (int, optional): internal schema version (default 1)
     *  - transformName (string, optional): transformer identifier (default: class name / "callable")
     *  - transformVer (string, optional): transformer version tag/commit (default: env APP_VERSION/GIT_SHA/"")
     *  - transformCfg (string, optional): hash of transformer mapping/config
     *  - updateMutable (bool, default true)
     *  - logTable (string, default "logged_doc")
     *  - stateTable (string, default "mutable_doc")
     */
    public function transformToInternal(
        array|object $rawDoc,
        string $extId,
        string $ifName,
        TransformerInterface|callable $transform,
        array|object $rawMeta = [],
        ?string $sat = null,
        array $options = []
    ): array {
        $pdo = $this->pdo;

        $ifUuid = (string)($options['ifUuid'] ?? '');
        if ($ifUuid === '') {
            throw new \InvalidArgumentException('transformToInternal() requires $options["ifUuid"].');
        }

        $ifInstance = (string)($options['ifInstance'] ?? '');

        $defaultStream = (string)($options['stream'] ?? '');
        if ($defaultStream === '') {
            throw new \InvalidArgumentException('transformToInternal() requires $options["stream"] (upstream entity/table name).');
        }
        $rawStream = (string)($options['rawStream'] ?? $defaultStream);

        $schemaVer     = (int)($options['schemaVer'] ?? 1);
        $transformName = (string)($options['transformName'] ?? $this->inferTransformName($transform));
        $transformVer  = (string)($options['transformVer'] ?? (getenv('APP_VERSION') ?: getenv('GIT_SHA') ?: ''));
        $transformCfg  = $options['transformCfg'] ?? null;

        $updateMutable = (bool)($options['updateMutable'] ?? true);
        $logTable      = (string)($options['logTable'] ?? 'logged_doc');
        $stateTable    = (string)($options['stateTable'] ?? 'mutable_doc');

        $pdo->beginTransaction();
        try {
            // 1) RAW ingest append (or latest if duplicate)
            $rawRow = $this->appendIfChanged($rawDoc, $extId, $ifName, $rawMeta, $sat, $rawStream);
            $extUuid    = (string)$rawRow['uuid'];
            $extVersion = (string)$rawRow['version'];
            $extIat     = (string)$rawRow['iat'];

            // 2) Transform RAW -> INTERNAL items
            $rawDocArr  = (array)$rawDoc;
            $rawMetaArr = (array)$rawMeta;

            $items = is_callable($transform)
                ? $transform($rawDocArr, $rawMetaArr, $extId)
                : $transform->transform($rawDocArr, $rawMetaArr, $extId);

            $log   = new DocChangeLog($pdo, $logTable, $this->schema);
            $state = new DocState($pdo, $stateTable, null, $this->schema); // no implicit logging here

            $out = [];
            foreach ($items as $it) {
                $itDoc  = (array)($it['doc'] ?? []);
                $itMeta = (array)($it['meta'] ?? []);

                // 2a) Enforce stable internal uuid
                $intUuid = (string)($it['uuid'] ?? ($itDoc['uuid'] ?? ''));
                if ($intUuid === '') {
                    $kind = (string)($it['kind'] ?? '');
                    $key  = (string)($it['key']  ?? '');
                    if ($kind === '' || $key === '') {
                        throw new \LogicException(
                            'Transformer must provide either "uuid" or ("kind" + "key") for stable idempotency.'
                        );
                    }
                    $intUuid = UuidTools::stableForSource("internal:$kind", $ifUuid, $key);
                }
                $itDoc['uuid'] = $intUuid;

                $itemStream = (string)($it['stream'] ?? $defaultStream);

                // 2b) Bake meta (keeping root keys schemaVer/nbf/exp/actor* intact; no actor keys invented here)
                $baked = $this->bakeInternalMeta(
                    $itMeta,
                    schemaVer: $schemaVer,
                    ifName: $ifName,
                    ifUuid: $ifUuid,
                    ifInstance: $ifInstance,
                    transformName: $transformName,
                    transformVer: $transformVer,
                    transformCfg: $transformCfg,
                    stream: $itemStream,
                    extId: $extId,
                    extUuid: $extUuid,
                    extVersion: $extVersion,
                    extIat: $extIat
                );

                $intSat = $it['sat'] ?? $sat;

                // 3) Append internal history (consecutive dedupe)
                $verRow = $log->appendIfChanged($itDoc, $baked, $intSat);

                // 4) Upsert current state (idempotent; no log here)
                if ($updateMutable) {
                    $state->put($itDoc, $baked, $intSat);
                }

                $out[] = ['uuid' => $intUuid, 'version' => (string)$verRow['version']];
            }

            $pdo->commit();
            return ['raw' => $rawRow, 'internal' => $out];

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function inferTransformName(TransformerInterface|callable $transform): string
    {
        if (is_object($transform) && !$transform instanceof \Closure) {
            return $transform::class;
        }
        return 'callable';
    }

    /**
     * Bake the agreed meta schema into the existing meta (no actor keys are invented here).
     * Root keys (schemaVer/nbf/exp/actor*) are preserved.
     */
    private function bakeInternalMeta(
        array $meta,
        int $schemaVer,
        string $ifName,
        string $ifUuid,
        string $ifInstance,
        string $transformName,
        string $transformVer,
        ?string $transformCfg,
        string $stream,
        string $extId,
        string $extUuid,
        string $extVersion,
        string $extIat
    ): array {
        if (!isset($meta['schemaVer'])) {
            $meta['schemaVer'] = $schemaVer;
        }

        $meta['ifName'] = $ifName;
        $meta['ifUuid'] = $ifUuid;
        if ($ifInstance !== '') {
            $meta['ifInstance'] = $ifInstance;
        }

        $meta['transform'] = is_array($meta['transform'] ?? null) ? $meta['transform'] : [];
        $meta['transform']['name'] = $transformName;
        if ($transformVer !== '') {
            $meta['transform']['ver'] = $transformVer;
        }
        if ($transformCfg !== null && $transformCfg !== '') {
            $meta['transform']['cfg'] = $transformCfg;
        }

        $srcItem = [
            'stream'     => $stream,
            'extId'      => $extId,
            'extUuid'    => $extUuid,
            'extVersion' => $extVersion,
            'extIat'     => (int)$extIat,
        ];

        $src = $meta['src'] ?? [];
        if (!is_array($src)) { $src = []; }

        // dedupe by (extUuid, extVersion, stream)
        $seen = [];
        foreach ($src as $s) {
            if (!is_array($s)) { continue; }
            $k = ($s['extUuid'] ?? '') . '|' . ($s['extVersion'] ?? '') . '|' . ($s['stream'] ?? '');
            $seen[$k] = true;
        }
        $kNew = $srcItem['extUuid'] . '|' . $srcItem['extVersion'] . '|' . $srcItem['stream'];
        if (!isset($seen[$kNew])) {
            $src[] = $srcItem;
        }

        $meta['src'] = array_values($src);
        return $meta;
    }
}
