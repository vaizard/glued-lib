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
final class DbMutable extends Base
{
    /** @var ?string Target history table (append-only) */
    private ?string $logTable;

    public function __construct(PDO $pdo, string $table, ?string $logTable = null, ?string $schema = 'glued')
    {
        parent::__construct($pdo, $table, $schema);
        $this->logTable = $logTable;
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
    public function upsert(array|object $doc, array|object $meta = new \stdClass(), ?string $sat = null): array
    {
        $uuid = (string) ((is_array($doc) ? ($doc['uuid'] ?? null) : ($doc->uuid ?? null)) ?? Uuid::uuid4());
        [$d, $m] = $this->normalize($doc, $meta, $uuid);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        $this->query = "
        WITH up AS (
          INSERT INTO {$this->schema}.{$this->table} AS t (doc, meta, sat, iat, uat)
          VALUES (:doc::jsonb, :meta::jsonb, :sat, now(), now())
          ON CONFLICT ({$this->uuidCol}) DO UPDATE
            SET doc = EXCLUDED.doc,
                meta = EXCLUDED.meta,
                sat  = EXCLUDED.sat,
                uat  = now()
            -- idempotent: only update when something actually changed
            WHERE (t.doc, t.meta, t.sat) IS DISTINCT FROM (EXCLUDED.doc, EXCLUDED.meta, EXCLUDED.sat)
          RETURNING
            t.{$this->uuidCol} AS uuid,
            t.version,
            t.iat,
            encode(t.nonce, 'hex') AS nonce
        )
        -- prefer the row affected by INSERT/UPDATE; if no-op, fall back to the existing row
        SELECT u.uuid, u.version, u.iat, u.nonce
          FROM up u
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
        // Fallback safety if SELECT returns nothing for some reason
        return $row ?: ['uuid'=>$uuid, 'version'=>'', 'iat'=>'', 'nonce'=>''];
    }


    /**
     * Upsert mutable row and append to log (dedup by (uuid, nonce)).
     */
    public function upsertWithLog(array|object $doc, array|object $meta = [], ?string $sat = null): string
    {
        $uuid = (string) ((is_array($doc) ? ($doc['uuid'] ?? null) : ($doc->uuid ?? null)) ?? Uuid::uuid4());
        [$d, $m] = $this->normalize($doc, $meta, $uuid);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        $this->query = "
        WITH up AS (
          INSERT INTO {$this->schema}.{$this->table} (doc, meta, sat, iat, uat)
          VALUES (:doc::jsonb, :meta::jsonb, :sat, now(), now())
          ON CONFLICT ({$this->uuidCol}) DO UPDATE
            SET doc = EXCLUDED.doc,
                meta = EXCLUDED.meta,
                sat  = EXCLUDED.sat,
                uat  = now()
          RETURNING uuid, doc, meta, sat
        )
        INSERT INTO {$this->schema}.{$this->logTable} (uuid, doc, meta, iat, sat)
        SELECT uuid, doc, meta, now(), sat FROM up
        ON CONFLICT (uuid, nonce) DO NOTHING
        RETURNING uuid
        ";
        $this->params = [
            ':doc'  => $docJson,
            ':meta' => $metaJson,
            ':sat'  => $sat,
        ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $uuidRet = $this->stmt->fetchColumn();
        $this->reset();
        return $uuidRet ?: $uuid;
    }

    /**
     * JSON Merge Patch DOC + LOG.
     */
    public function patchDoc(string $uuid, array|object $patch, ?string $sat = null, array|object $metaForLog = []): array
    {
        $patched = $this->getApply($patch, $uuid);

        $meta = $metaForLog ?: $this->fetchRawMeta($uuid) ?? [];
        $this->upsertWithLog($patched, $meta, $sat);
        return $patched;
    }

    /**
     * JSON Merge Patch META + LOG.
     */
    public function patchMeta(string $uuid, array|object $metaPatch, ?string $sat = null): array
    {
        if (empty($metaPatch)) throw new \InvalidArgumentException('Empty meta patch.');
        $curr = $this->get($uuid);
        if (!$curr) throw new \RuntimeException('Document not found.', 404);

        $patcher = new JsonMergePatch();
        $metaNew = (array)$patcher->apply((object)($curr['meta'] ?? []), (object)$metaPatch);

        $this->upsertWithLog($curr, $metaNew, $sat);
        $res = $this->get($uuid);
        return $res ?? [];
    }

    /**
     * JSON Merge Patch DOC (NO LOG).
     *
     * @return array Patched DOC (raw)
     */
    public function patchDocNoLog(string $uuid, array|object $patch, ?string $sat = null): array
    {
        $patched = $this->getApply($patch, $uuid);
        $docJson = json_encode($patched, $this->jsonFlags);

        $this->query = "
        UPDATE {$this->schema}.{$this->table}
           SET doc = :doc::jsonb,
               uat = now(),
               sat = COALESCE(:sat, sat)
         WHERE {$this->uuidCol} = :uuid
        ";
        $this->params = [
            ':doc'  => $docJson,
            ':sat'  => $sat,
            ':uuid' => $uuid,
        ];
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
    public function patchMetaNoLog(string $uuid, array|object $metaPatch, ?string $sat = null): array
    {
        if (empty($metaPatch)) throw new \InvalidArgumentException('Empty meta patch.');
        $currentMeta = $this->fetchRawMeta($uuid) ?? [];

        $patcher = new JsonMergePatch();
        $metaNew = (array)$patcher->apply((object)$currentMeta, (object)$metaPatch);
        $metaJson = json_encode($metaNew, $this->jsonFlags);

        $this->query = "
        UPDATE {$this->schema}.{$this->table}
           SET meta = :meta::jsonb,
               uat  = now(),
               sat  = COALESCE(:sat, sat)
         WHERE {$this->uuidCol} = :uuid
        ";
        $this->params = [
            ':meta' => $metaJson,
            ':sat'  => $sat,
            ':uuid' => $uuid,
        ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $this->reset();

        return $metaNew;
    }

    /**
     * Soft-delete (NO LOG): set dat=now(); optionally tombstone doc with {"_deleted": true}.
     */
    public function softDeleteNoLog(string $uuid, ?string $sat = null, array|object $metaExtra = [], bool $tombstoneDoc = true): void
    {
        $metaJson = json_encode((array)$metaExtra, $this->jsonFlags);

        $docExpr = $tombstoneDoc
            ? "{$this->docCol} || jsonb_build_object('_deleted', true)"
            : $this->docCol;

        $this->query = "
        UPDATE {$this->schema}.{$this->table}
           SET doc  = {$docExpr},
               dat  = now(),
               uat  = now(),
               sat  = COALESCE(:sat, sat),
               meta = COALESCE(:meta::jsonb, meta)
         WHERE {$this->uuidCol} = :uuid
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
     * Soft-delete (WITH LOG).
     */
    public function softDelete(string $uuid, ?string $sat = null, array|object $metaExtra = []): void
    {
        $metaJson = json_encode((array)$metaExtra, $this->jsonFlags);

        $this->query = "
        WITH upd AS (
          UPDATE {$this->schema}.{$this->table}
             SET doc = {$this->docCol} || jsonb_build_object('_deleted', true),
                 dat = now(),
                 uat = now(),
                 sat = COALESCE(:sat, sat),
                 meta = COALESCE(:meta::jsonb, meta)
           WHERE {$this->uuidCol} = :uuid
          RETURNING uuid, doc, meta, sat, dat
        )
        INSERT INTO {$this->schema}.{$this->logTable} (uuid, doc, meta, iat, sat, dat)
        SELECT uuid, doc, meta, now(), sat, dat FROM upd
        ON CONFLICT (uuid, nonce) DO NOTHING
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
     * Bitemporal read (from logged_doc).
     */
    public function getAsOf(string $uuid, \DateTimeInterface $asOf): ?array
    {
        $this->query = "
        SELECT {$this->docCol} || jsonb_build_object(
                 'meta', meta, 'iat', iat, 'dat', dat, 'sat', sat
               )
          FROM {$this->schema}.logged_doc
         WHERE uuid = :u
           AND period @> :asof::timestamptz
         ORDER BY iat DESC
         LIMIT 1
        ";
        $this->params = [':u' => $uuid, ':asof' => $asOf->format('c')];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();
        return $row ? json_decode($row, true) : null;
    }

    /**
     * @param object|array $patch
     * @param string $uuid
     * @return array
     */
    public function getApply(object|array $patch, string $uuid): array
    {
        if (empty($patch)) throw new \InvalidArgumentException('Empty patch.');
        $currentDoc = $this->fetchRawDoc($uuid);
        if (!$currentDoc) throw new \RuntimeException('Document not found.', 404);

        $patcher = new JsonMergePatch();
        $patched = (array)$patcher->apply((object)$currentDoc, (object)$patch);
        $patched['uuid'] = $uuid;
        return $patched;
    }
}
