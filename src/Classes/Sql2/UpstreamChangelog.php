<?php
declare(strict_types=1);

namespace Glued\Lib\Classes\Sql2;

use PDO;

/**
 * Transform one RAW upstream document (+ meta) into one or more INTERNAL records.
 *
 * Contract:
 * - Each yielded item MUST contain:
 *     - 'doc' (array)
 *
 * - It MAY contain:
 *     - 'uuid' (string): stable INTERNAL UUID (preferred)
 *       OR:
 *     - 'kind' (string) + 'key' (string): used to derive a stable INTERNAL UUID if 'uuid' missing
 *     - 'meta' (array): additional INTERNAL meta to merge/bake (no actor keys are invented by the pipeline)
 *     - 'sat' (?string): effective "source at time" override for that internal item
 *     - 'stream' (string): upstream entity/table name for provenance (stored as meta.src[].stream)
 *
 * Notes:
 * - "stream" in this file always means the UPSTREAM entity/table name (semantic provenance).
 * - Identity (UUID) concerns are handled separately by the ingest pipeline via UUID namespacing.
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
interface UpstreamTransformer
{
    public function transform(array $rawDoc, array $rawMeta, ?string $extId = null): iterable;
}

/**
 * External ingest (append-only, stable uuid per ext_id via UUID v5).
 *
 * Table contract (upstream_changelog):
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
final class UpstreamChangelog extends Base
{
    public function __construct(PDO $pdo, string $table, ?string $schema = null)
    {
        parent::__construct($pdo, $table, $schema);
    }

    /*
     * $raw = $upstream->appendIfChanged($doc, $extId, $ifName, $meta, $sat, stream: 'acord.packset');
     */

    /**
     * Append a versioned RAW ingest row (consecutive dedupe, per-uuid xact advisory lock).
     *
     * Terminology:
     * - $stream: upstream entity/table name (semantic provenance meaning in this class).
     * - $uuidScope: OPTIONAL identity-only discriminator used ONLY to namespace extUuid.
     *     Default behavior: if $uuidScope is empty, $stream is used.
     *
     * Why $uuidScope exists:
     * - Sometimes you want extUuid to be stable for something *more specific* than (ifName, stream, extId),
     *   e.g. different upstream endpoints feeding the same stream while sharing extId ranges.
     * - In that case, you keep semantic provenance "stream" intact, and provide a separate uuidScope.
     *
     * IMPORTANT:
     * - extUuid stability should normally include the upstream stream (entity/table) to avoid collisions
     *   when different streams reuse the same extId inside the same upstream_changelog table.
     *
     * NOTE:
     * - We force doc.uuid := extUuid inside normalizeToJson() to avoid volatile upstream 'uuid' fields
     *   causing nonce churn.
     *
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function appendIfChanged(
        array|object $doc,
        string $extId,
        string $ifName,
        array|object $meta = [],
        ?string $sat = null,
        ?string $stream = null,
        ?string $uuidScope = null
    ): array {
        $stream = $stream !== null ? trim($stream) : '';
        $uuidScope = $uuidScope !== null ? trim($uuidScope) : '';

        // Identity-only scope defaults to semantic stream.
        $idScope = $uuidScope !== '' ? $uuidScope : $stream;

        // Fold identity discriminator (idScope) into the namespace key used for stable UUIDv5.
        // normalizeToJson() forces it to doc.uuid (prevents upstream random uuid fields from breaking dedupe).
        $sourceKey = $idScope !== '' ? ($ifName . ':' . $idScope) : $ifName;

        $stable = UuidTools::stableForSource($this->table, $sourceKey, $extId);
        [$uuid, $docJson, $metaJson] = $this->normalizeToJson($doc, $meta, $stable);

        $this->query = "
        WITH lock AS (
          SELECT pg_advisory_xact_lock(hashtextextended(:uuid::text, 0)) AS ok
        ),
        candidate AS (
          SELECT
            :uuid::uuid                                          AS uuid,
            :ext                                                 AS ext_id,
            :doc::jsonb                                          AS doc,
            :meta::jsonb                                         AS meta,
            :sat                                                 AS sat,
            (EXTRACT(EPOCH FROM clock_timestamp())*1000)::bigint AS iat,
            decode(md5((:doc::jsonb)::text), 'hex')              AS nonce_calc
          FROM lock
        ),
        last AS (
          SELECT l.nonce
            FROM {$this->schema}.{$this->table} l
           WHERE l.{$this->uuidCol} = :uuid::uuid
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
         WHERE t.{$this->uuidCol} = :uuid::uuid
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
     * Ingest RAW, transform to INTERNAL, optionally:
     * - append INTERNAL changelog (log table),
     * - upsert INTERNAL mutable state (state table).
     *
     * Stream semantics:
     * - 'stream' ALWAYS means the UPSTREAM entity/table name (e.g. "acord.packset").
     *
     * RAW identity vs provenance:
     * - RAW extUuid is stable per (ifName, discriminator, extId), where discriminator defaults to 'stream'.
     * - If you need RAW extUuid to fork beyond (ifName, stream, extId), provide 'rawUuidScope' (identity-only).
     *   Provenance still records 'stream' as the upstream entity/table name.
     *
     * Options (explicit routing; log/state required):
     * - ifUuid (string, REQUIRED): UUID of the interface configuration (identity discriminator for internal uuids)
     * - ifInstance (string, optional): remote-defined instance/tenant id
     *
     * - stream (string, REQUIRED): upstream entity/table name (provenance)
     * - rawUuidScope (string, optional): identity-only discriminator for RAW extUuid namespacing (default: stream)
     *
     * - schemaVer (int, optional): internal schema version (default 1)
     * - transformName (string, optional): transformer identifier (default: class name / "callable")
     * - transformVer (string, optional): transformer version tag (e.g. git sha); default: env GIT_SHA
     * - transformCfg (string, optional): hash of transformer mapping/config
     *
     * - log (string|false, REQUIRED):
     *     - string: write INTERNAL changelog to this table
     *     - false: skip INTERNAL changelog
     *
     * - state (string|false, REQUIRED):
     *     - string: upsert INTERNAL mutable state to this table
     *     - false: skip INTERNAL mutable state
     *
     * @return array{
     *   raw: array{uuid:string,version:string,iat:string,nonce:string},
     *   internal: list<array{uuid:string,version:string}>
     * }
     */
    public function transformToInternal(
        array|object $rawDoc,
        string $extId,
        string $ifName,
        UpstreamTransformer|callable $transform,
        array|object $rawMeta = [],
        ?string $sat = null,
        array $options = []
    ): array {
        $pdo = $this->pdo;

        $ifUuid = trim((string)($options['ifUuid'] ?? ''));
        if ($ifUuid === '') {
            throw new \InvalidArgumentException('transformToInternal() requires $options["ifUuid"].');
        }

        $ifInstance = trim((string)($options['ifInstance'] ?? ''));

        $stream = trim((string)($options['stream'] ?? ''));
        if ($stream === '') {
            throw new \InvalidArgumentException('transformToInternal() requires $options["stream"] (upstream entity/table name).');
        }

        $rawUuidScope = trim((string)($options['rawUuidScope'] ?? ''));
        if ($rawUuidScope === '') {
            $rawUuidScope = $stream;
        }

        $gitSha        = getenv('GIT_SHA');
        $schemaVer     = (int)($options['schemaVer'] ?? 1);
        $transformName = (string)($options['transformName'] ?? $this->inferTransformName($transform));
        $transformVer  = trim((string)($options['transformVer'] ?? ($gitSha !== false ? $gitSha : '')));
        $transformCfg  = $options['transformCfg'] ?? null;

        [$logEnabled, $logTable]     = self::resolveDestTable($options, 'log');
        [$stateEnabled, $stateTable] = self::resolveDestTable($options, 'state');

        $pdo->beginTransaction();
        try {
            // 1) RAW ingest append (or latest if duplicate)
            $rawRow = $this->appendIfChanged(
                $rawDoc,
                $extId,
                $ifName,
                $rawMeta,
                $sat,
                stream: $stream,
                uuidScope: $rawUuidScope
            );

            $extUuid    = (string)$rawRow['uuid'];
            $extVersion = (string)$rawRow['version'];
            $extIat     = (string)$rawRow['iat'];

            // 2) Transform RAW -> INTERNAL items
            $rawDocArr  = (array)$rawDoc;
            $rawMetaArr = (array)$rawMeta;

            $items = is_callable($transform)
                ? $transform($rawDocArr, $rawMetaArr, $extId)
                : $transform->transform($rawDocArr, $rawMetaArr, $extId);

            $log = null;
            if ($logEnabled) {
                $log = new DocChangelog($pdo, $logTable, $this->schema);
            }

            $state = null;
            if ($stateEnabled) {
                $state = new DocSnapshot($pdo, $stateTable, null, $this->schema); // no implicit logging here
            }

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
                        throw new \LogicException('Transformer must provide either "uuid" or ("kind" + "key") for stable idempotency.');
                    }
                    $intUuid = UuidTools::stableForSource("internal:$kind", $ifUuid, $key);
                }
                $itDoc['uuid'] = $intUuid;

                // Upstream provenance stream (entity/table) for this internal item.
                $itemStream = (string)($it['stream'] ?? $stream);

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
                $version = '';
                if ($logEnabled) {
                    /** @var DocChangelog $log */
                    $verRow = $log->appendIfChanged($itDoc, $baked, $intSat);
                    $version = (string)($verRow['version'] ?? '');
                }

                // 4) Upsert current state (idempotent; no log here)
                if ($stateEnabled) {
                    /** @var DocSnapshot $state */
                    $state->put($itDoc, $baked, $intSat);
                }

                $out[] = ['uuid' => $intUuid, 'version' => $version];
            }

            $pdo->commit();
            return ['raw' => $rawRow, 'internal' => $out];

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function inferTransformName(UpstreamTransformer|callable $transform): string
    {
        if (is_object($transform) && !$transform instanceof \Closure) {
            return $transform::class;
        }
        return 'callable';
    }

    /**
     * Bake the agreed meta schema into the existing meta (no actor keys invented here).
     * Root keys (schemaVer/nbf/exp/actor*) are preserved.
     *
     * Provenance:
     * - Adds/merges meta.src[] items describing upstream inputs that led to this internal state.
     * - meta.src[].stream always refers to the upstream entity/table name.
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

        // Dedupe by (extUuid, extVersion, stream)
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

    /**
     * @return array{0:bool,1:string} [enabled, tableName]
     */
    private static function resolveDestTable(array $options, string $key): array
    {
        if (!array_key_exists($key, $options)) {
            throw new \InvalidArgumentException(sprintf('transformToInternal() requires option "%s" (table name|string) or false.', $key));
        }

        $v = $options[$key];

        if ($v === false) {
            return [false, ''];
        }

        if (!is_string($v)) {
            throw new \InvalidArgumentException(sprintf('Option "%s" must be a table name (string) or false.', $key));
        }

        $t = trim($v);
        if ($t === '') {
            throw new \InvalidArgumentException(sprintf('Option "%s" must be a non-empty table name or false.', $key));
        }

        return [true, $t];
    }

}
