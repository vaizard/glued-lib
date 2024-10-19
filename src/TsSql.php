<?php

declare(strict_types=1);
namespace Glued\Lib;

use \PDO;

/**
 * TsSQL class extending GenericSql.
 *
 * This class inherits 1:1 functionality from the GenericSql class. Additional methods
 * and properties ensure interactions with TimescaleDB compatible tables. These are most
 * commonly used in IF services. To denote the difference, tsdb compliant tables use the
 * naming scheme table_tsdb and table_tsdb_ext, where the latter captures the upstream
 * interface data in its raw form/structure converted to json and the latter stores the
 * transformed representation internally compatible within glued.
 */

class TsSql extends Sql {
    /** @var \PDOStatement The PDOStatement instance for upstream database queries. */
    public $stmtUpstream;

    /** @var \PDOStatement The PDOStatement instance for internal (transformed upostream) database queries. */
    public $stmtInternal;

    /** @var string The table name for database queries handling raw upstream data. */
    public string $tableUpstream;

    /** @var string The table name for database queries handling transformed data. */
    public string $tableInternal;

    /** @var array An array of metadata columns that are to be auto-appended to the `doc` object. Overriding the GenericSQL class here. */
    public array $metaColumns = ['nonce', 'created_at'];

    /** @var string Name of the external data unique key. */
    public string $extKey;

    public function __construct(PDO $pdo, string $table, $extKey)
    {
        // Call the parent constructor
        parent::__construct($pdo, $table);

        // Extend the constructor by adding more logic
        $this->tableUpstream = "{$table}_ext";
        $this->tableInternal = "{$table}";
        $this->extKey = $extKey;
    }

    /**
     * Stores upstream data ($docs) uniquely identified by the external unique key
     * $this->extKey. A database insert is perform always when the latest $doc in database
     * differs from the $doc provided by upstream (also when reverting a $doc to any of its
     * previous states). Furthermore, $docs are transformed to their internal representation
     * and stored as well.
     * a $doc
     * @param array|object $docs array of documents passed from upstream interface
     * @param $xf Selective\Transformer object ensuring transformation of upstream docs
     * to their internal representation     * @return bool
     */
    public function CommonCreateBatch(array | object $docs, $xf)
    {
        $qInternal = "
            INSERT INTO glued.{$this->tableInternal} (doc, created_at)
            VALUES (:doc, :created_at)
            ON CONFLICT (nonce, created_at) DO NOTHING;
        ";

        $qUpstream = "
            INSERT INTO {$this->tableUpstream} (uuid, doc)
            SELECT
                COALESCE(latest.uuid, gen_random_uuid()),
                upstream.doc
            FROM (
                 SELECT
                     :doc::jsonb AS doc,
                     (:doc::jsonb ->> '{$this->extKey}') AS ext_id,
                     DECODE(MD5(:doc::jsonb::TEXT), 'hex') AS nonce
                 ) AS upstream
            LEFT JOIN LATERAL (
                SELECT uuid, nonce
                FROM {$this->tableUpstream}
                WHERE ext_id = upstream.ext_id
                ORDER BY created_at DESC
                LIMIT 1
            ) AS latest ON TRUE
            WHERE latest.nonce IS DISTINCT FROM upstream.nonce
               OR latest.nonce IS NULL
            RETURNING uuid, created_at;
        ";

        $this->stmtInternal = $this->pdo->prepare($qInternal);
        $this->stmtUpstream = $this->pdo->prepare($qUpstream);

        $this->pdo->beginTransaction();
        foreach ($docs as $doc) {
            $jsonDoc = json_encode($doc);
            $this->stmtUpstream->bindParam(':doc', $jsonDoc);
            $this->stmtUpstream->execute();
            $insert = $this->stmtUpstream->fetch(\PDO::FETCH_ASSOC);
            if ($insert) {
                $doc['uuid'] = $insert['uuid'];
                $jsonDoc = json_encode($xf->toArray($doc));
                $this->stmtInternal->bindParam(':doc', $jsonDoc);
                $this->stmtInternal->bindParam(':created_at', $insert['created_at']);
                $this->stmtInternal->execute();
            }
        }
        return $this->pdo->commit();
    }
}