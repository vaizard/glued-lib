<?php

declare(strict_types=1);
namespace Glued\Lib\Classes\Sql2;

use \PDO;
use Ramsey\Uuid\Uuid;
use Rs\Json\Merge\Patch as JsonMergePatch;

/**
 * Raw ingest repository (append-only; as-received feed).
 *
 * Table contract (ingest):
 * - PK (nonce, iat), doc, meta, ext_id, iat, sat
 */
final class IngestAppend extends Base
{

    public function __construct(PDO $pdo, string $table, ?string $schema = 'glued')
    {
        parent::__construct($pdo, $table, $schema);
    }

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
     * Append a raw ingest row.
     *
     * @return array{uuid:string,version:string,iat:string}
     */
    public function log(array|object $doc, string $extId, array|object $meta = new \stdClass(), ?string $sat = null): array
    {
        [$d, $m] = $this->normalize($doc, $meta);
        $docJson  = json_encode($d, $this->jsonFlags);
        $metaJson = json_encode($m, $this->jsonFlags);

        $this->query = "
        INSERT INTO {$this->schema}.{$this->table} (doc, meta, ext_id, sat, iat)
        VALUES (:doc::jsonb, :meta::jsonb, :ext, :sat, now())
        RETURNING uuid, version, iat, encode(nonce, 'hex') AS nonce
        ";
        $this->params = [
            ':doc'  => $docJson,
            ':meta' => $metaJson,
            ':ext'  => $extId,
            ':sat'  => $sat,
        ];
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        /** @var array<string,string> */
        $row = (array)$this->stmt->fetch(PDO::FETCH_ASSOC);
        $this->reset();
        return $row;
    }
}

