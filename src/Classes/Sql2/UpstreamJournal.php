<?php

declare(strict_types=1);
namespace Glued\Lib\Classes\Sql2;

use PDO;

/**
 * Raw upstream journal (append-only; as-received feed).
 *
 * Table contract (glued.upstream_journal):
 * - ext_id         text     -- upstream id (required)
 * - {$uuidCol}     uuid     DEFAULT gen_random_uuid()      -- PK(uuid) (row id)
 * - {$versionCol}  uuid     DEFAULT uuidv7()               -- time-sortable tie-break
 * - {$docCol}      jsonb    -- raw payload (AS RECEIVED)
 * - {$metaCol}     jsonb    -- provenance/audit; may hold 'nbf'/'exp' (unix ms if present, consistent with other tables)
 * - nonce          bytea    -- STORED md5(doc::text)
 * - iat            bigint   -- unix time (ms) (append time) DEFAULT EXTRACT(EPOCH ...)*1000
 * - uat            bigint   -- VIRTUAL: same as iat
 * - dat            bigint?  -- optional (NULL by default)
 * - sat            text     -- raw source timestamp (free text)
 * - period         int8range GENERATED: clamped from meta.nbf/meta.exp/dat/iat
 *
 * Indexes:
 * - (ext_id, iat DESC, version DESC)
 * - (nonce, iat)
 *
 * Envelope (selectEnvelope):
 *   doc || {
 *     "_meta": meta || {
 *        journalUuid, journalVersion, iat, uat, sat, nonce(hex)
 *     }
 *   }
 */
final class UpstreamJournal extends Base
{
    public function __construct(PDO $pdo, string $table, ?string $schema = null)
    {
        parent::__construct($pdo, $table, $schema);
    }

    /**
     * Build the read envelope for ingest rows.
     * Note: `uat` is a VIRTUAL column equal to `iat` in the table.
     */
    protected function selectEnvelope(): string
    {
        return "{$this->selectModifier} (
            {$this->docCol}
            || jsonb_build_object(
                '_meta', {$this->metaCol}
                    || jsonb_build_object(
                        'journalUuid', {$this->uuidCol}::text,
                        'journalVersion', {$this->versionCol}::text,
                        'iat', iat,
                        'uat', uat,
                        'sat', sat,
                        'nonce', encode(nonce, 'hex')
                    )
            )
        )";
    }

    /**
     * Append a raw ingest row (append-only), Unix time in **milliseconds** (DB default).
     *
     * @param array|object $doc   Raw payload to store.
     * @param string       $extId External identifier of the upstream record.
     * @param array|object $meta  Provenance/audit metadata (merged into `_meta` on reads).
     * @param string|null  $sat   Source timestamp as text (unparsed, optional).
     *
     * @return array{uuid:string,version:string,iat:string,nonce:string}
     */
    public function append(array|object $doc, string $extId, array|object $meta = [], ?string $sat = null): array
    {
        [$d, $m]  = $this->normalize($doc, $meta);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        // Let the table defaults compute iat (ms) and version (uuidv7)
        $this->query = "
            INSERT INTO {$this->schema}.{$this->table} (doc, meta, ext_id, sat)
            VALUES (:doc::jsonb, :meta::jsonb, :ext, :sat)
            RETURNING
                {$this->uuidCol}     AS uuid,
                {$this->versionCol}  AS version,
                iat,
                encode(nonce, 'hex') AS nonce
        ";
        $this->params = [
            ':doc'  => $docJson,
            ':meta' => $metaJson,
            ':ext'  => $extId,
            ':sat'  => $sat,
        ];

        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) { $this->stmt->bindValue($k, $v); }
        $this->stmt->execute();

        /** @var array{uuid:string,version:string,iat:string,nonce:string} $row */
        $row = (array)$this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();
        return $row ?: ['uuid'=>'', 'version'=>'', 'iat'=>'', 'nonce'=>''];
    }
}
