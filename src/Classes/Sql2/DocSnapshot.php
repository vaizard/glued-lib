<?php
declare(strict_types=1);

namespace Glued\Lib\Classes\Sql2;

use PDO;
use Rs\Json\Merge\Patch as JsonMergePatch;

/**
 * Mutable documents repository.
 *
 * Supports:
 *  - putAndLog(): change + append history (doc_changelog)
 *  - put(): change only (no log)
 *  - patchDocAndLog()/patchMetaAndLog(): merge-patch + log
 *  - patchDoc()/patchMeta(): merge-patch without log
 *  - softDeleteAndLog(): mark deleted + log
 *  - softDelete(): mark deleted without log
 *
 * Discoverability:
 * - Snapshot table:  doc_snapshot     (pass as $table)
 * - Changelog table: doc_changelog    (pass as $logTable)
 * - Pairing: DocChangelog is the append-only history for DocSnapshot.
 */
final class DocSnapshot extends Base
{
    /** @var ?string Target history table (append-only) */
    private ?string $logTable;

    public function __construct(PDO $pdo, string $table, ?string $logTable = null, ?string $schema = null)
    {
        parent::__construct($pdo, $table, $schema);
        $this->logTable = $logTable;
    }

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
                'Use put()/softDelete(), or construct with a log table.'
            );
        }
        return $this->logTable;
    }

    /**
     * Upsert mutable row (NO LOG), idempotent.
     *
     * - Inserts when uuid is new.
     * - On conflict, updates only if (doc, meta, sat) changed; otherwise it's a no-op (uat not bumped).
     * - Always returns: uuid, version, iat, nonce(hex).
     *
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function put(array|object $doc, array|object $meta = [], ?string $sat = null): array
    {
        [$uuid, $docJson, $metaJson] = $this->normalizeToJson($doc, $meta);

        $this->query = "
    WITH ts AS (
      SELECT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint AS ms
    ),
    up AS (
      INSERT INTO {$this->schema}.{$this->table} AS t (doc, meta, sat, iat, uat)
      SELECT :doc::jsonb, :meta::jsonb, :sat, ts.ms, ts.ms
        FROM ts
      ON CONFLICT ({$this->uuidCol}) DO UPDATE
        SET doc = EXCLUDED.doc,
            meta = EXCLUDED.meta,
            sat  = EXCLUDED.sat,
            uat  = (SELECT ms FROM ts),
            version = uuidv7()
      WHERE (t.doc, t.meta, t.sat) IS DISTINCT FROM (EXCLUDED.doc, EXCLUDED.meta, EXCLUDED.sat)
      RETURNING
        t.{$this->uuidCol} AS uuid,
        t.version,
        t.iat,
        encode(t.nonce, 'hex') AS nonce
    )
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
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function putAndLog(array|object $doc, array|object $meta = [], ?string $sat = null): array
    {
        $logTable = $this->requireLogTable();
        [$uuid, $docJson, $metaJson] = $this->normalizeToJson($doc, $meta);

        $this->query = "
    WITH ts AS (
      SELECT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint AS ms
    ),
    up AS (
      INSERT INTO {$this->schema}.{$this->table} AS t (doc, meta, sat, iat, uat)
      SELECT :doc::jsonb, :meta::jsonb, :sat, ts.ms, ts.ms
        FROM ts
      ON CONFLICT ({$this->uuidCol}) DO UPDATE
        SET doc = EXCLUDED.doc,
            meta = EXCLUDED.meta,
            sat  = EXCLUDED.sat,
            uat  = (SELECT ms FROM ts),
            version = uuidv7()
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
      SELECT u.uuid, u.doc, u.meta, (SELECT ms FROM ts), u.sat
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
            ':uuid' => $uuid,
        ];

        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) { $this->stmt->bindValue($k, $v); }
        $this->stmt->execute();

        /** @var array{uuid:string,version:string,iat:string,nonce:string} $row */
        $row = (array)$this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();

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
        $patched['uuid'] = $uuid;

        $meta = !empty((array)$metaForLog) ? (array)$metaForLog : ($this->fetchRawMeta($uuid) ?? []);
        $this->putAndLog($patched, $meta, $sat);

        return $patched;
    }

    /**
     * JSON Merge Patch META + LOG.
     *
     * Returns a fresh merged envelope (doc + meta + timestamps).
     */
    public function patchMetaAndLog(string $uuid, array|object $metaPatch, ?string $sat = null): array
    {
        if (empty((array)$metaPatch)) throw new \InvalidArgumentException('Empty meta patch.');

        $currentDoc = $this->fetchRawDoc($uuid);
        if (!$currentDoc) throw new \RuntimeException('Document not found.', 404);

        $currentMeta = $this->fetchRawMeta($uuid) ?? [];
        $metaNew = (array)(new JsonMergePatch())->apply((object)$currentMeta, (object)$metaPatch);

        // doc unchanged, meta updated
        $currentDoc['uuid'] = $uuid;
        $this->putAndLog($currentDoc, $metaNew, $sat);

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
     * Merges metaExtra into existing meta (does not overwrite).
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
               meta = t.meta || :meta::jsonb
          FROM ts
         WHERE t.{$this->uuidCol} = :uuid
        ";

        $this->params = [ ':sat' => $sat, ':meta' => $metaJson, ':uuid' => $uuid ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $this->reset();
    }

    /**
     * Soft-delete (WITH LOG) — uses one timestamp for dat/uat and for the log row's iat.
     * Merges metaExtra into existing meta (does not overwrite).
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
                 meta = t.meta || :meta::jsonb
            FROM ts
           WHERE t.{$this->uuidCol} = :uuid
          RETURNING
            t.uuid, t.doc, t.meta, t.sat, t.dat,
            decode(md5((t.doc - 'uuid')::text), 'hex') AS nonce_calc
        )
        INSERT INTO {$this->schema}.{$logTable} (uuid, doc, meta, iat, sat, dat)
        SELECT u.uuid, u.doc, u.meta, u.dat, u.sat, u.dat
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

    /**
     * Bitemporal read (from log table) returning the same envelope as get().
     */
    public function getAsOf(string $uuid, \DateTimeInterface $asOf): ?array
    {
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

