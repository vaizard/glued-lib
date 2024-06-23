<?php

declare(strict_types=1);
namespace Glued\Lib;

use PDO;
use Ramsey\Uuid\Uuid;

abstract class GenericSql
{
    /** @var PDO The PDO instance for database connection. */
    public $pdo;

    /** @var PDOStatement The PDOStatement instance for database queries. */
    public $stmt;

    /** @var string The name of the data column. Data are stored a jsonb object.
     * SQL generated metadata (md5 nonce, creted at, updated at, etc.) are appended
     * by select methods.
     */
    public string $dataColumn = 'doc';

    /** @var array An array of metadata columns that are to be auto-appended to the `doc` object. */
    public array $metaColumns = ['nonce', 'created_at', 'updated_at'];

    /** @var string A select modifier, by default adding the $metaColumns to the `doc` object. */
    public string $selectModifier;

    /** @var string The name of the UUID column. The column is stored, generated from doc->>uuid */
    public string $uuidColumn = 'uuid';

    /** @var string The schema name for database tables. */
    protected string $schema = 'glued';

    /** @var string The table name for database queries. */
    protected string $table;

    /** @var array An array of WHERE conditions for the getAll() method. */
    protected array $wheres = [];

    /** @var array An array of columns with a unique constraint other then uuid to ignore during upsert operations. */
    protected array $upsertIgnore = ['(nonce)'];

    /** @var string The string to append to the query to change an insert into an upsert. */
    protected string $upsertString = "
        ON CONFLICT (uuid) DO UPDATE
        SET doc = EXCLUDED.doc,
            updated_at = CURRENT_TIMESTAMP
    ";

    public function __construct(PDO $pdo, string $table)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->selectModifier = "{$this->metaObject()} ||";
    }

    /**
     * Generates a Version 4 (random) UUID.
     *
     * @return string A string representation of the generated UUID.
     */
    public function uuid() {
        return Uuid::uuid4()->toString();
    }

    /**
     * Converts an associative array to a JSON string and creates or updates a UUID field.
     *
     * @param array|object $doc The associative array | object to be converted to JSON.
     * @param string $uuid The UUID to be set on the JSON document.
     * @return string The JSON representation of the array with the UUID added.
     */
    public function toJson(array | object $doc, $uuid)
    {
        $doc['uuid'] = $uuid;
        return json_encode($doc);
    }

    /**
     * Builds a PostgreSQL query element which constructs a JSON object containing metadata columns (metaColumns)
     *
     * @return string The JSON object string representation containing metadata columns.
     */
    public function metaObject()
    {
        $columns = implode(", ", array_map(function($column) {
            return "'$column', $column";
        }, $this->metaColumns));
        return "jsonb_build_object({$columns})";
    }

    /**
     * Handles expected exceptions thrown during upsert operations.
     *
     * @param \Exception $e The exception thrown during the upsert operation.
     * @throws \Exception If the exception code is not "23505" or if the upsertIgnore conditions are not met.
     */
    private function handleUpsertException($e)
    {
        if ($e->getCode() === "23505" && count(array_filter($this->upsertIgnore, function($s) use ($e) {
                return strpos($e->getMessage(), $s) !== false;
            })) > 0) {}
        else throw $e;
    }

    /**
     * Builds a WHERE clause.
     *
     * @param string $column The name of the column to apply the condition on.
     * @param string $condition The condition to be applied (e.g., '=', '>', '<', 'LIKE', etc.).
     * @param mixed $value The value to compare against.
     * @param string $logicalOperator The logical operator to be used to combine this condition with the previous ones (default is 'AND').
     * @return self Returns the current instance of the class to allow method chaining.
     */
    public function where(string $column, string $condition, $value, string $logicalOperator = 'AND'): self
    {
        $this->wheres[] = compact('column', 'condition', 'value', 'logicalOperator');
        return $this;
    }

    /**
     * Inserts or upserts a json document in the database. Ensures that the document contains a uuid.
     * When upserting, on uuid column collision (primary key generated from doc.uuid), the doc and
     * updated_at columns are updated by default. This behavior can be changed throug the upsertString.
     *
     * Additional unique constraints are supported by handling collision exeptions on columns listed in
     * upsertIgnore. By default, exceptions over duplicate nonce column values are handled as the unique
     * constraint on the on nonce column (the md5 hash of doc - 'uuid') is the most frequent scenario.
     * Tables with a unique nonce constraint will not allow duplicate documents differing only in their uuid.
     *
     * NOTE: To identify, if a create statement created or updated a row, or if a unique constraint prevented
     * an update, use stmt->rowCount() to test if the number of affected rows was 0 or 1.
     *
     * @param array $doc The associative array representing the json document to be inserted or upserted.
     * @param bool $upsert Whether to perform an upsert operation (default is false).
     * @param bool $handleUpsertIgnore Whether to get the uuid of stored (:doc - (ignoredColumn)) on conflict in the
     * ignoredColumn. See $upsertIgnore. This is typically usefull when you want to prevent generating new rows with
     * the same doc under different uuids. To achieve this, a nonce column generated as md5(doc - 'uuid') holds the
     * md5 hash of the doc without the uuid and prevents duplicate doc entries under different uuids. Flipping
     * $handleUpsertIgnore to true would cause create($json, true, true) to return the same uuid stored in database on
     * a) insert of a doc
     * b) re-insert of the doc while providing a different uuid (data arent updated, original/stored uuid is returned)
     * c] update the doc having the same uuid
     *
     * @return string The UUID of the newly created record.
     */
    public function create(array $doc, $upsert = false, $handleUpsertIgnore = false): mixed
    {
        $uuid = $doc['uuid'] ?? $this->uuid();
        $doc = $this->toJson($doc, $uuid);
        $cond = $upsert ? $this->upsertString : "";
        $cond .= " RETURNING uuid";
        try {
            $this->stmt = $this->pdo->prepare("INSERT INTO {$this->schema}.{$this->table} ({$this->dataColumn}) VALUES (:doc) {$cond}");
            $this->stmt->bindParam(':doc', $doc);
            $this->stmt->execute();
        } catch (\Exception $e) {
            if ($upsert) $this->handleUpsertException($e);
            if ($upsert && $handleUpsertIgnore) {
                $this->stmt = $this->pdo->prepare("SELECT uuid FROM {$this->schema}.{$this->table} WHERE nonce = decode(md5((:doc::jsonb - 'uuid')::text), 'hex')");
                $this->stmt->bindParam(':doc', $doc);
                $this->stmt->execute();
            }
        }
        return $this->stmt->fetchColumn();
    }


    /**
     * Inserts or upserts multiple JSON documents into the database. Each document must contain a uuid.
     * When upserting, on uuid column collision (primary key generated from doc.uuid), the doc and
     * updated_at columns are updated by default. This behavior can be changed through the upsertString.
     *
     * Additional unique constraints are supported by handling collision exceptions on columns listed in
     * upsertIgnore. By default, exceptions over duplicate nonce column values are handled as the unique
     * constraint on the nonce column (the md5 hash of doc - 'uuid') is the most frequent scenario.
     * Tables with a unique nonce constraint will not allow duplicate documents differing only in their uuids.
     *
     * NOTE: To identify if a create statement created or updated a row, or if a unique constraint prevented
     * an update, use stmt->rowCount() to test if the number of affected rows was 0 or 1.
     *
     * @param array[] $docs An array of associative arrays representing the JSON documents to be inserted or upserted.
     * @param bool $upsert Whether to perform an upsert operation (default is false).
     * @return void
     */
    public function createBatch(array $docs, $upsert = false): void
    {
        $cond = $upsert ? $this->upsertString : "";
        $this->stmt = $this->pdo->prepare("INSERT INTO {$this->schema}.{$this->table} ({$this->dataColumn}) VALUES (:doc) {$cond}");
        foreach ($docs as $doc) {
            $uuid = $doc['uuid'] ?? $this->uuid();
            $doc = $this->toJson($doc, $uuid);
            try {
                $this->stmt->bindParam(':doc', $doc);
                $this->stmt->execute();
            } catch (\Exception $e) {
                if ($upsert) $this->handleUpsertException($e);
            }
        }
    }

    /**
     * Retrieves a JSON document from the database based on the provided UUID.
     *
     * This method constructs and executes a SELECT query to fetch the document.
     * The returned document is a JSON object from the dataColumn appended with
     * generated metadata.
     *
     * @param string $uuid The UUID of the document to retrieve.
     * @return bool|array Returns the retrieved JSON document as an associative array,
     *                    or false if no document is found with the provided UUID.
     */
    public function get(string $uuid): bool | array
    {
        $this->stmt = $this->pdo->prepare("SELECT {$this->selectModifier} {$this->dataColumn} FROM {$this->schema}.{$this->table} WHERE {$this->uuidColumn} = :uuid");
        $this->stmt->bindParam(':uuid', $uuid);
        $this->stmt->execute();
        $res = $this->stmt->fetchColumn();
        if ($res) { return json_decode($res, true); }
        else return $res;
    }

    /**
     * Updates a JSON document in the database identified by its UUID.
     *
     * This method updates the document with the provided UUID with the new data provided in $body.
     * The updated_at column is also updated to reflect the modification time.
     *
     *  NOTE: To identify if a row was updated, use stmt->rowCount() to get the number of affected rows.
     *
     * @param string $uuid The UUID of the document to update.
     * @param mixed $body The new data to update the document with.
     * @return void
     * @throws \Exception If the UUID provided in $body doesn't match the UUID of the document being updated.
     */
    public function update(string $uuid, $body): void
    {
        if ($body['uuid'] !== $uuid) { throw new \Exception('Document UUID doesn\'t match update UUID.'); }
        $doc = json_encode($body);
        $this->stmt = $this->pdo->prepare("UPDATE {$this->schema}.{$this->table} SET {$this->dataColumn} = :doc, updated_at = CURRENT_TIMESTAMP WHERE {$this->uuidColumn} = :uuid");
        $this->stmt->bindParam(':doc', $doc);
        $this->stmt->bindParam(':uuid', $uuid);
        $this->stmt->execute();
    }

    /**
     * Deletes a JSON document from the database based on the provided UUID.
     *
     * This method deletes the document with the provided UUID from the database.
     * NOTE: To identify if a row was updated, use stmt->rowCount() to get the number of affected rows.
     *
     * @param string $uuid The UUID of the document to delete.
     * @return void
     */
    public function delete(string $uuid): void
    {
        $this->stmt = $this->pdo->prepare("DELETE FROM {$this->schema}.{$this->table} WHERE {$this->uuidColumn} = :uuid");
        $this->stmt->bindParam(':uuid', $uuid);
        $this->stmt->execute();
    }

    /**
     * Retrieves all JSON documents from the database.
     *
     * This method retrieves all documents from the specified table in the database.
     * It applies any specified WHERE conditions constructed by calling it after a chain
     * of where() method.
     *
     * @return array Returns an array of associative arrays representing the retrieved JSON documents.
     */

    public function getAll(): array
    {
        $query = "SELECT {$this->selectModifier} {$this->dataColumn} FROM {$this->schema}.{$this->table}";
        $conds = [];
        if (!empty($this->wheres)) {
            foreach ($this->wheres as $c) { $conds[] = "{$c['column']} {$c['condition']} :" . md5($c['column']); }
            $query .= " WHERE " . implode(" {$c['logicalOperator']} ", $conds);
        }
        $this->stmt = $this->pdo->prepare($query);
        if (!empty($this->wheres)) {
            foreach ($this->wheres as $c) { $this->stmt->bindValue(":" . md5($c['column']), $c['value']); }
        }
        $this->stmt->execute();
        return $this->stmt->fetchAll(\PDO::FETCH_FUNC, function ($json) { return json_decode($json); });
    }

}

/**
 * SQL class extending GenericSql.
 *
 * This class inherits 1:1 functionality from the GenericSql class. It is provided
 * as a default to interact with databases.
 */

class Sql extends GenericSql {}