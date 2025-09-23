<?php
declare(strict_types=1);

namespace Glued\Lib\Classes\Sql2;

use PDO;
use Ramsey\Uuid\Uuid;
use Rs\Json\Merge\Patch as JsonMergePatch;

/**
 * Mutable documents repository.
 *
 * Supports:
 *  - upsertWithLog(): change + append history (logged_doc)
 *  - upsert(): change only (no log)
 *  - patchDoc()/patchMeta(): merge-patch + log
 *  - patchDocNoLog()/patchMetaNoLog(): merge-patch without log
 *  - softDelete(): mark deleted + log
 *  - softDeleteNoLog(): mark deleted without log
 */
final class DocState extends Base
{
    /** @var ?string Target history table (append-only) */
    private ?string $logTable;

    public function __construct(PDO $pdo, string $table, ?string $logTable = null, ?string $schema = 'glued')
    {
        parent::__construct($pdo, $table, $schema);
        $this->logTable = $logTable;
    }

    /** True if a log table is configured. */
    public function hasLog(): bool
    {
        return !empty($this->logTable);
    }

    /** @return non-empty-string */
    private function requireLogTable(): string
    {
        if (!$this->logTable) {
            throw new \LogicException(
                static::class . ' requires a configured $logTable for this operation. ' .
                'Use upsert()/softDeleteNoLog(), or construct with a log table.'
            );
        }
        return $this->logTable;
    }

    /**
     * Upsert mutable row (NO LOG).
     *
     * @param array|object $doc   JSON document; if no 'uuid', a new one is generated.
     * @param array|object $meta  JSON meta
     * @param string|null  $sat   Raw source timestamp
     * @return string             Document UUID
     */
    /**
     * Upsert mutable row (NO LOG), idempotent.
     *
     * - Inserts when uuid is new.
     * - On conflict, updates only if (doc, meta, sat) changed; otherwise it's a no-op (uat not bumped).
     * - Always returns: uuid, version, iat, nonce(hex).
     *
     * @param array|object $doc   JSON document; if no 'uuid', a new one is generated and injected.
     * @param array|object $meta  JSON meta
     * @param string|null  $sat   Raw source timestamp
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function put(array|object $doc, array|object $meta = new \stdClass(), ?string $sat = null): array
    {
        $uuid = (string) ((is_array($doc) ? ($doc['uuid'] ?? null) : ($doc->uuid ?? null)) ?? Uuid::uuid4());
        [$d, $m] = $this->normalize($doc, $meta, $uuid);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        $this->query = "
    WITH up AS (
      INSERT INTO {$this->schema}.{$this->table} AS t (doc, meta, sat, iat, uat)
      VALUES (:doc::jsonb, :meta::jsonb, :sat,
              (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint,
              (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint)
      ON CONFLICT ({$this->uuidCol}) DO UPDATE
        SET doc = EXCLUDED.doc,
            meta = EXCLUDED.meta,
            sat  = EXCLUDED.sat,
            uat  = (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint,
            version = uuidv7() -- << bump on real update
            -- idempotent: only update when something actually changed
      WHERE (t.doc, t.meta, t.sat) IS DISTINCT FROM (EXCLUDED.doc, EXCLUDED.meta, EXCLUDED.sat)
      RETURNING
        t.{$this->uuidCol} AS uuid,
        t.version,
        t.iat,
        encode(t.nonce, 'hex') AS nonce
    )
    -- prefer the row affected by INSERT/UPDATE; if no-op, fall back to the existing row
    SELECT uuid, version, iat, nonce FROM up
    UNION ALL
    SELECT t.{$this->uuidCol} AS uuid,
           t.version,
           t.iat,
           encode(t.nonce, 'hex') AS nonce
      FROM {$this->schema}.{$this->table} t
     WHERE t.{$this->uuidCol} = :uuid
       AND NOT EXISTS (SELECT 1 FROM up)
    LIMIT 1;
    ";

        $this->params = [ ':doc'=>$docJson, ':meta'=>$metaJson, ':sat'=>$sat, ':uuid'=>$uuid ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) { $this->stmt->bindValue($k, $v); }
        $this->stmt->execute();

        /** @var array{uuid:string,version:string,iat:string,nonce:string} $row */
        $row = (array)$this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();
        return $row ?: ['uuid'=>$uuid, 'version'=>'', 'iat'=>'', 'nonce'=>''];
    }


    /**
     * Upsert mutable row and append to log (consecutive-dedupe).
     *
     * - Inserts when uuid is new.
     * - On conflict, updates only if (doc, meta, sat) changed; otherwise it's a no-op (uat/version not bumped).
     * - Bumps version = uuidv7() on real UPDATE.
     * - Appends to {$this->schema}.{$this->logTable} **only if** the new snapshot's nonce differs
     *   from the last logged nonce for the same uuid.
     * - Returns (like upsert): uuid, version, iat(ms), nonce(hex).
     *
     * @param array|object $doc
     * @param array|object $meta
     * @param string|null  $sat
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function putAndLog(array|object $doc, array|object $meta = [], ?string $sat = null): array
    {
        $logTable = $this->requireLogTable();
        $uuid = (string) ((is_array($doc) ? ($doc['uuid'] ?? null) : ($doc->uuid ?? null)) ?? Uuid::uuid4());
        [$d, $m] = $this->normalize($doc, $meta, $uuid);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        $this->query = "
    WITH up AS (
      INSERT INTO {$this->schema}.{$this->table} AS t (doc, meta, sat, iat, uat)
      VALUES (:doc::jsonb, :meta::jsonb, :sat,
              (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint,
              (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint)
      ON CONFLICT ({$this->uuidCol}) DO UPDATE
        SET doc = EXCLUDED.doc,
            meta = EXCLUDED.meta,
            sat  = EXCLUDED.sat,
            uat  = (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint,
            version = uuidv7()
      -- only update when something actually changed (idempotent)
      WHERE (t.doc, t.meta, t.sat) IS DISTINCT FROM (EXCLUDED.doc, EXCLUDED.meta, EXCLUDED.sat)
      RETURNING
        t.{$this->uuidCol} AS uuid,
        t.version,
        t.iat,
        encode(t.nonce, 'hex') AS nonce,
        t.doc,
        t.meta,
        t.sat,
        decode(md5((t.doc - 'uuid')::text), 'hex') AS nonce_calc
    ),
    ins AS (
      INSERT INTO {$this->schema}.{$logTable} (uuid, doc, meta, iat, sat)
      SELECT u.uuid, u.doc, u.meta, (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint, u.sat
        FROM up u
       WHERE COALESCE(
               (SELECT l.nonce
                  FROM {$this->schema}.{$logTable} l
                 WHERE l.uuid = u.uuid
                 ORDER BY l.iat DESC, l.version DESC
                 LIMIT 1),
               '\x'::bytea
             ) IS DISTINCT FROM u.nonce_calc
      RETURNING 1
    )
    -- prefer the row affected by INSERT/UPDATE; if no-op, fall back to the existing row
    SELECT uuid, version, iat, nonce
      FROM up
    UNION ALL
    SELECT t.{$this->uuidCol} AS uuid,
           t.version,
           t.iat,
           encode(t.nonce, 'hex') AS nonce
      FROM {$this->schema}.{$this->table} t
     WHERE t.{$this->uuidCol} = :uuid
       AND NOT EXISTS (SELECT 1 FROM up)
    LIMIT 1;
    ";

        $this->params = [
            ':doc'  => $docJson,
            ':meta' => $metaJson,
            ':sat'  => $sat,
            ':uuid' => $uuid,   // for the fallback SELECT
        ];

        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) { $this->stmt->bindValue($k, $v); }
        $this->stmt->execute();

        /** @var array{uuid:string,version:string,iat:string,nonce:string} $row */
        $row = (array)$this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();

        // Fallback safety if SELECT returns nothing for some reason
        return $row ?: ['uuid'=>$uuid, 'version'=>'', 'iat'=>'', 'nonce'=>''];
    }


    /**
     * JSON Merge Patch DOC + LOG.
     */
    public function patchDocAndLog(string $uuid, array|object $patch, ?string $sat = null, array|object $metaForLog = []): array
    {
        if (empty((array)$patch)) throw new \InvalidArgumentException('Empty patch.');
        $currentDoc = $this->fetchRawDoc($uuid);
        if (!$currentDoc) throw new \RuntimeException('Document not found.', 404);
        $patched = (array)(new JsonMergePatch())->apply((object)$currentDoc, (object)$patch);
        $patched['uuid'] = $uuid; // keep invariant
        $meta = $metaForLog ?: ($this->fetchRawMeta($uuid) ?? []);
        $this->upsertWithLog($patched, $meta, $sat);
        return $patched;
    }

    /**
     * JSON Merge Patch META + LOG.
     */
    public function patchMetaAndLog(string $uuid, array|object $metaPatch, ?string $sat = null): array
    {
        if (empty((array)$metaPatch)) throw new \InvalidArgumentException('Empty meta patch.');
        $curr = $this->get($uuid);
        if (!$curr) throw new \RuntimeException('Document not found.', 404);
        $metaNew = (array)(new JsonMergePatch())->apply((object)($curr['meta'] ?? []), (object)$metaPatch);
        // Log the change by writing a new version (doc unchanged, meta updated)
        $this->upsertWithLog($curr, $metaNew, $sat);
        // Return fresh merged envelope (doc + meta + timestamps)
        return $this->get($uuid) ?? [];
    }

    /**
     * JSON Merge Patch DOC (NO LOG).
     *
     * @return array Patched DOC (raw)
     */
    public function patchDoc(string $uuid, array|object $patch, ?string $sat = null): array
    {
        if (empty((array)$patch)) throw new \InvalidArgumentException('Empty patch.');
        $currentDoc = $this->fetchRawDoc($uuid);
        if (!$currentDoc) throw new \RuntimeException('Document not found.', 404);
        $patched = (array)(new JsonMergePatch())->apply((object)$currentDoc, (object)$patch);
        $patched['uuid'] = $uuid;
        $docJson = json_encode($patched, $this->jsonFlags);

        $this->query = "
            UPDATE {$this->schema}.{$this->table}
               SET doc = :doc::jsonb,
                   uat = (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint,
                   sat = COALESCE(:sat, sat)
            WHERE {$this->uuidCol} = :uuid
        ";
        $this->params = [ ':doc' => $docJson, ':sat' => $sat, ':uuid' => $uuid ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $this->reset();
        return $patched;
    }

    /**
     * JSON Merge Patch META (NO LOG).
     *
     * @return array New META (raw)
     */
    public function patchMeta(string $uuid, array|object $metaPatch, ?string $sat = null): array
    {
        if (empty((array)$metaPatch)) throw new \InvalidArgumentException('Empty meta patch.');
        $currentMeta = $this->fetchRawMeta($uuid) ?? [];
        $metaNew = (array)(new JsonMergePatch())->apply((object)$currentMeta, (object)$metaPatch);
        $metaJson = json_encode($metaNew, $this->jsonFlags);

        $this->query = "
            UPDATE {$this->schema}.{$this->table}
               SET meta = :meta::jsonb,
                   uat  = (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint,
                   sat  = COALESCE(:sat, sat)
            WHERE {$this->uuidCol} = :uuid
        ";
        $this->params = [ ':meta' => $metaJson, ':sat' => $sat, ':uuid' => $uuid ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $this->reset();

        return $metaNew;
    }

    /**
     * Soft-delete (NO LOG): set dat=now(); optionally tombstone doc with {"_deleted": true}.
     */
    public function softDelete(string $uuid, ?string $sat = null, array|object $metaExtra = [], bool $tombstoneDoc = true): void
    {
        $metaJson = json_encode((array)$metaExtra, $this->jsonFlags);

        $docExpr = $tombstoneDoc
            ? "{$this->docCol} || jsonb_build_object('_deleted', true)"
            : $this->docCol;

        $this->query = "
        WITH ts AS (
          SELECT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint AS ms
        )
        UPDATE {$this->schema}.{$this->table} AS t
           SET doc  = {$docExpr},
               dat  = ts.ms,
               uat  = ts.ms,
               sat  = COALESCE(:sat, t.sat),
               meta = COALESCE(:meta::jsonb, t.meta)
          FROM ts
         WHERE t.{$this->uuidCol} = :uuid
    ";
        $this->params = [
            ':sat'  => $sat,
            ':meta' => $metaJson,
            ':uuid' => $uuid,
        ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $this->reset();
    }

    /**
     * Soft-delete (WITH LOG) — uses one timestamp for dat/uat and for the log row's iat.
     */
    public function softDeleteAndLog(string $uuid, ?string $sat = null, array|object $metaExtra = []): void
    {
        $logTable = $this->requireLogTable();
        $metaJson = json_encode((array)$metaExtra, $this->jsonFlags);

        $this->query = "
        WITH ts AS (
          SELECT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint AS ms
        ),
        upd AS (
          UPDATE {$this->schema}.{$this->table} AS t
             SET doc  = {$this->docCol} || jsonb_build_object('_deleted', true),
                 dat  = ts.ms,
                 uat  = ts.ms,
                 sat  = COALESCE(:sat, t.sat),
                 meta = COALESCE(:meta::jsonb, t.meta)
            FROM ts
           WHERE t.{$this->uuidCol} = :uuid
          RETURNING
            t.uuid, t.doc, t.meta, t.sat, t.dat,
            decode(md5((t.doc - 'uuid')::text), 'hex') AS nonce_calc
        )
        INSERT INTO {$this->schema}.{$logTable} (uuid, doc, meta, iat, sat, dat)
        SELECT u.uuid, u.doc, u.meta, u.dat /* iat == dat */, u.sat, u.dat
          FROM upd u
         WHERE COALESCE(
                 (SELECT l.nonce
                    FROM {$this->schema}.{$logTable} l
                   WHERE l.uuid = u.uuid
                   ORDER BY l.iat DESC, l.version DESC
                   LIMIT 1),
                 '\x'::bytea
               ) IS DISTINCT FROM u.nonce_calc
    ";
        $this->params = [ ':sat' => $sat, ':meta' => $metaJson, ':uuid' => $uuid ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $this->reset();
    }


    /* ----------------- internals ----------------- */

    private function fetchRawDoc(string $uuid): ?array
    {
        $this->query = "SELECT {$this->docCol} FROM {$this->schema}.{$this->table} WHERE {$this->uuidCol} = :u";
        $this->stmt  = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':u', $uuid);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();
        return $row ? json_decode($row, true) : null;
    }

    private function fetchRawMeta(string $uuid): ?array
    {
        $this->query = "SELECT {$this->metaCol} FROM {$this->schema}.{$this->table} WHERE {$this->uuidCol} = :u";
        $this->stmt  = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':u', $uuid);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();
        return $row ? json_decode($row, true) : null;
    }

    /**
     * Bitemporal read (from log table) returning the same envelope as get().
     */
    public function getAsOf(string $uuid, \DateTimeInterface $asOf): ?array
    {
        // ms since epoch (bigint) to match int8range(period)
        $asOfMs = ((int)$asOf->format('U')) * 1000 + (int)$asOf->format('v');
        $logTable = $this->requireLogTable();

        $order = "iat DESC, {$this->versionCol} DESC";

        $this->query = "
        SELECT {$this->selectEnvelope()}
          FROM {$this->schema}.{$logTable}
         WHERE {$this->uuidCol} = :u
           AND period @> :asof::bigint
         ORDER BY {$order}
         LIMIT 1
    ";
        $this->params = [ ':u' => $uuid, ':asof' => $asOfMs ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();

        return $row ? json_decode($row, true) : null;
    }



}