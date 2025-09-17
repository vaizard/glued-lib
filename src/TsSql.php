<?php

declare(strict_types=1);
namespace Glued\Lib;

use \PDO;

/**
 * TsSQL is a class that extends the Sql class to support provide TimescaleDB compatibility through
 * additional methods. Original methods of the Sql class are inherited completely. Typically the
 * Sql class expects the following table schema:
 *
 * CREATE TABLE "glued"."some_table" (
 *   uuid uuid generated always as (((doc->>'uuid'::text))::uuid) stored not null,
 *   doc jsonb not null,
 *   nonce bytea generated always as (decode(md5((doc - 'uuid')::text), 'hex')) stored,
 *   created_at timestamp default CURRENT_TIMESTAMP,
 *   updated_at timestamp default CURRENT_TIMESTAMP,
 *   PRIMARY KEY (uuid)
 * );
 *
 * Timescale series tables omit the updated_at column, as they create a new copy of a doc
 * anytime a doc (identified by its uuid) changes and also use a combination of nonce and created_at
 * as the primary key.
 *
 * The most common use-case for Timescale databases is caching data from foreign interfaces while
 * tracking every change and then transforming the foreign data to their internal representation.
 * Therefore, this classes' constructor instantiates always with the upstream-internal couple of
 * properties, such as:
 *
 *   $this->tableUpstream = "{$table}_ext";
 *   $this->tableInternal = "{$table}";
 *
 * Accordingly, the database schema typically bifurcates:
 *
 * CREATE TABLE "glued"."if_service_tsdb_ext" (
 *   uuid uuid DEFAULT gen_random_uuid() NOT NULL,
 *   doc jsonb NOT NULL,
 *   nonce bytea GENERATED ALWAYS AS (decode(md5((doc::text)), 'hex')) STORED,
 *   created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
 *   ext_id text GENERATED ALWAYS AS ((doc ->> 'external_unique_id')::int::text) STORED NOT NULL,
 *   PRIMARY KEY (nonce, created_at)
 * );
 * CREATE INDEX idx_if_service_ext_id_created_at ON if_service_tsdb_ext (ext_id, created_at DESC);
 *
 * CREATE TABLE "glued"."if_service_tsdb" (
 *   uuid uuid generated always as (((doc->>'uuid'::text))::uuid) stored not null,
 *   doc jsonb NOT NULL,
 *   nonce bytea generated always as (decode(md5(((doc - 'uuid')::text)), 'hex')) stored,
 *   created_at timestamp with time zone not null,
 *   PRIMARY KEY (nonce, created_at)  -- Ensure unique combination of document content and timestamp
 * );
 *
 * Selects are then expected to be done on if_service_tsdb with
 *
 *  $this->selectModifier = " DISTINCT ON (uuid) {$db->selectModifier} ";
 *  $this->orderBy = " created_at DESC ";
 */

class TsSql extends Sql {
    /** @var \PDOStatement The PDOStatement instance for upstream database queries. */
    public $stmtUpstream;

    /** @var \PDOStatement The PDOStatement instance for internal (transformed upstream) database queries. */
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
     * Inserts a batch of upstream json documents and their internal representations.
     *
     * This method processes a collection of JSON documents received from an upstream interface,
     * ensuring that each document is stored uniquely based on an external unique key specified by
     * `$this->extKey`. The upstream table is updated only if the most recent document stored (identified
     * by the external key and a computed nonce from the document's JSON) differs from the incoming document,
     * or if no record exists for that external identifier.
     *
     * For each document that results in a new insertion (or an update to a previous state) in the upstream table,
     * the method retrieves the generated UUID and creation timestamp. It then uses the provided transformer
     * (`$transformer`) to convert the upstream document into its internal representation and inserts the transformed document
     * into the internal table using the returned creation timestamp.
     *
     * Both the upstream and internal insertions are executed within a single transaction for atomicity.
     *
     * @param array|object $docs An array or iterable collection of JSON documents received from the upstream interface.
     * @param \Selective\Transformer $transformer A transformer object that converts upstream documents to their internal representation.
     * @return bool Returns true if the transaction commits successfully; false otherwise.
     */
    public function insertBatchUpstreamAndInternal(array | object $docs, $transformer)
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
                $jsonDoc = json_encode($transformer->toArray($doc));
                $this->stmtInternal->bindParam(':doc', $jsonDoc);
                $this->stmtInternal->bindParam(':created_at', $insert['created_at']);
                $this->stmtInternal->execute();
            }
        }
        return $this->pdo->commit();
    }

    /**
     * @deprecated Use insertBatchUpstreamAndInternal() instead.
     */
    public function CommonCreateBatch(array|object $docs, $xf)
    {
        return $this->insertBatchUpstreamAndInternal($docs, $xf);
    }
}


