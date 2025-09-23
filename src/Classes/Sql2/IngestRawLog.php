<?php

declare(strict_types=1);
namespace Glued\Lib\Classes\Sql2;

use \PDO;

/**
 * Raw ingest repository (append-only; as-received feed).
 *
 * Table contract (glued.ingest_log):
 * - {$uuidCol}     uuid     DEFAULT gen_random_uuid()
 * - {$versionCol}  uuid     DEFAULT uuidv7()               -- PK(version)
 * - {$docCol}      jsonb    -- raw payload
 * - {$metaCol}     jsonb    -- provenance/audit; may hold 'nbf'/'exp' (unix seconds)
 * - nonce          bytea    -- STORED md5(doc::text)
 * - iat            bigint   -- unix seconds (append time) DEFAULT EXTRACT(EPOCH ...)
 * - uat            bigint   -- VIRTUAL: same as iat
 * - sat            text     -- raw source timestamp (free text)
 * - ext_id         text     -- upstream id (required)
 * - period         int8range VIRTUAL int8range(COALESCE(meta->>'nbf', iat), meta->>'exp', '[)')
 *   (both nbf/exp read as BIGINT if present in meta)
 *
 * Indexes:
 * - (ext_id, iat DESC, version DESC)
 * - (nonce, iat)
 *
 * Envelope (selectEnvelope):
 *   doc || {
 *     "_meta": meta || {
 *        internalUuid, internalVersion, iat, uat, sat, nonce(hex)
 *     }
 *   }
 */
/**
 * Raw ingest repository (append-only; as-received feed).
 *
 * Table contract (ingest):
 * - PK (nonce, iat), doc, meta, ext_id, iat, sat
 */
final class IngestRawLog extends Base
{

    public function __construct(PDO $pdo, string $table, ?string $schema = 'glued')
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
                        'internalUuid', {$this->uuidCol}::text,
                        'internalVersion', {$this->versionCol}::text,
                        'iat', iat,
                        'uat', uat,
                        'sat', sat,
                        'nonce', encode(nonce, 'hex')
                    )
            )
        )";
    }



    /**
     * Append a raw ingest row (append-only), Unix time in **seconds** (DB default).
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

        // Let the table defaults compute iat (seconds) and version (uuidv7)
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

