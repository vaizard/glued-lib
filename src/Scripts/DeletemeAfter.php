
<?php
abstract class GenericSql2
{
    /** @var \PDO The PDO instance for database connection. */
    public $pdo;

    /** @var \PDOStatement The PDOStatement instance for database queries. */
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
    public string $schema = 'glued';

    /** @var string The table name for database queries. */
    public string $table;

    /** @var array An array of WHERE conditions for the getAll() method. */
    protected array $wheres = [];

    /** @var array An array of columns with a unique constraint other then uuid to ignore during upsert operations. */
    public array $upsertIgnore = ['(nonce)'];

    /** @var string The string to append to the query to change an insert into an upsert. */
    public string $upsertString = "
        ON CONFLICT (uuid) DO UPDATE
        SET doc = EXCLUDED.doc,
            updated_at = CURRENT_TIMESTAMP
    ";

    public string $orderBy = "";

    public string|int $limit = "ALL";
    public string $query = "";
    public array $params = [];
    public int $jsonEncodeOptions = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE;

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
        return json_encode($doc, $this->jsonEncodeOptions);
    }

    /**
     * Builds a PostgreSQL query element which constructs a JSON object containing metadata columns (metaColumns)
     *
     * @return string The JSON object string representation containing metadata columns.
     */
    public function metaObject()
    {
        $columns = implode(", ", array_map(function($column) {
            if ($column == 'nonce') { return "'$column', encode($column,'hex')"; }
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
    public function handleUpsertException($e)
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
     * Inserts a JSON document into the database.
     *
     * @param array $doc The associative array representing the JSON document to be inserted.
     * @return mixed The UUID of the newly created record.
     */
    public function insert(array $doc): mixed
    {
        $uuid = $doc['uuid'] ?? $this->uuid();
        $doc = $this->toJson($doc, $uuid);
        $cond = "RETURNING uuid";
        $this->query = "INSERT INTO {$this->schema}.{$this->table} ({$this->dataColumn}) VALUES (:doc) {$cond}";
        $this->params = [':doc' => $doc];
        $this->stmt = $this->pdo->prepare($this->query);
        $this->stmt->bindParam(':doc', $doc);
        $this->stmt->execute();
        return $this->stmt->fetchColumn();
    }

    /**
     * Inserts a JSON document into the database with upsert behavior.
     *
     * The INSERT query uses an "ON CONFLICT (uuid) DO UPDATE" clause ($this->upsertString) so that if
     * a conflict occurs on the UUID, the existing record is updated and its UUID is returned.
     *
     * In addition, if a conflict occurs due to another unique constraint (for example, a duplicate nonce),
     * an exception is thrown. When the $handleOtherUniqueConstraints flag is set to true, the exception
     * is caught, and a secondary query is executed that retrieves the UUID of the existing record based
     * on a hash computed from the document (ignoring its UUID). This ensures that repeated upsert
     * attempts for the same document yield the same UUID, providing idempotent behavior. To configure
     * unique constraints, see $this->upsertIgnore.
     *
     * @param array  $doc The associative array representing the JSON document.
     * @param bool   $handleOtherUniqueConstraints If true, on a conflict due to unique constraints
     *               (other than the UUID), the method will fetch and return the existing record's UUID.
     *               Defaults to false, if you set this to true, make sure to correctly set $this->upsertIgnore
     * @return mixed The UUID of the inserted or updated record, or the existing record's UUID if a conflict is detected.
     */
    public function upsert(array $doc, bool $handleOtherUniqueConstraints = false): mixed
    {
        $uuid = $doc['uuid'] ?? $this->uuid();
        $doc = $this->toJson($doc, $uuid);
        $cond = $this->upsertString . " RETURNING uuid";
        try {
            $this->query = "INSERT INTO {$this->schema}.{$this->table} ({$this->dataColumn}) VALUES (:doc) {$cond}";
            $this->params = [':doc' => $doc];
            $this->stmt = $this->pdo->prepare($this->query);
            $this->stmt->bindParam(':doc', $doc);
            $this->stmt->execute();
        } catch (\Exception $e) {
            if (!$handleOtherUniqueConstraints) { throw $e; }
            $this->handleUpsertException($e);
            $this->query = "SELECT uuid FROM {$this->schema}.{$this->table} WHERE nonce = decode(md5((:doc::jsonb - 'uuid')::text), 'hex')";
            $this->params = [':doc' => $doc];
            $this->stmt = $this->pdo->prepare($this->query);
            $this->stmt->bindParam(':doc', $doc);
            $this->stmt->execute();
        }
        return $this->stmt->fetchColumn();
    }

    /**
     * Inserts multiple JSON documents into the database.
     *
     * This method iterates over the provided documents and calls the single-document
     * insert() method for each. The entire operation is wrapped in a transaction
     * to ensure atomicity. It returns an array of UUIDs corresponding to each inserted record.
     *
     * @param array[] $docs An array of associative arrays representing the JSON documents.
     * @return array An array of UUIDs for the inserted records.
     * @throws \Exception If any insert operation fails, the transaction is rolled back and the exception is rethrown.
     */
    public function insertBatch(array $docs): array
    {
        $uuids = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($docs as $doc) {
                $uuids[] = $this->insert($doc);
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $uuids;
    }

    /**
     * Inserts multiple JSON documents into the database with upsert behavior.
     *
     * This method iterates over the provided documents and calls the single-document
     * upsert() method for each. The upsert() method uses an "ON CONFLICT (uuid) DO UPDATE"
     * clause to update existing records, and if a conflict occurs due to unique constraints
     * (other than the UUID), passing the $handleOtherUniqueConstraints flag as true will cause
     * the method to fetch and return the existing record's UUID.
     *
     * The entire operation is wrapped in a transaction to ensure atomicity. The method returns
     * an array of UUIDs corresponding to each inserted or updated record.
     *
     * @param array[] $docs An array of associative arrays representing the JSON documents.
     * @param bool $handleOtherUniqueConstraints If true, on a conflict due to unique constraints
     *               (other than the UUID conflict), the upsert() method will fetch and return the
     *               existing record's UUID. Defaults to false.
     * @return array An array of UUIDs for the inserted or updated records.
     * @throws \Exception If any upsert operation fails, the transaction is rolled back and the exception is rethrown.
     */
    public function upsertBatch(array $docs, bool $handleOtherUniqueConstraints = false): array
    {
        $uuids = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($docs as $doc) {
                $uuids[] = $this->upsert($doc, $handleOtherUniqueConstraints);
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $uuids;
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
        $this->query = "SELECT {$this->selectModifier} {$this->dataColumn} FROM {$this->schema}.{$this->table} WHERE {$this->uuidColumn} = :uuid";
        $this->query .= !empty($this->orderBy) ? " ORDER BY {$this->orderBy}" : '';
        $this->params = [':uuid' => $uuid];

        $this->stmt = $this->pdo->prepare($this->query);
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
     * @param array | object $body The new data to update the document with.
     * @return void
     * @throws \Exception If the UUID provided in $body doesn't match the UUID of the document being updated.
     */
    public function update(string $uuid, array | object $body): void
    {
        if (((array) $body)['uuid'] !== $uuid) { throw new \Exception('Document UUID doesn\'t match update UUID.'); }
        $doc = json_encode($body, $this->jsonEncodeOptions);
        $this->stmt = $this->pdo->prepare("UPDATE {$this->schema}.{$this->table} SET {$this->dataColumn} = :doc, updated_at = CURRENT_TIMESTAMP WHERE {$this->uuidColumn} = :uuid");
        $this->stmt->bindParam(':doc', $doc);
        $this->stmt->bindParam(':uuid', $uuid);
        $this->stmt->execute();
    }

    public function patch(string $uuid, array | object $patch): object
    {
        if (empty($patch)) { throw new \Exception('Empty patch doc.', 400); }
        $this->pdo->beginTransaction();
        $doc = $this->get($uuid);
        if (!$doc) { throw new \Exception('Empty patch doc.', 400); }
        $patchHandler = new JsonMergePatch();
        $new = $patchHandler->apply((object) $doc, (object) $patch);
        $this->update($uuid,$new);
        $this->pdo->commit();
        return $new;
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
        $this->query = "SELECT {$this->selectModifier} {$this->dataColumn} FROM {$this->schema}.{$this->table} AS doc";
        $conds = [];
        $this->params = [];

        if (!empty($this->wheres)) {
            foreach ($this->wheres as $index => $c) {
                $paramName = ":param{$index}";  // unique param name
                $conditionStr = "({$c['column']} {$c['condition']} {$paramName})"; // condition string
                $logicalOperator = isset($c['logicalOperator']) ? " {$c['logicalOperator']} " : ''; // Determine the logical operator (AND, OR), default to empty for the first condition
                $conds[] = ($index > 0 ? $logicalOperator : '') . $conditionStr; // Append the logical operator and condition string to the conditions array
                $this->params[$paramName] = $c['value']; // Store the parameter name and value for binding
            }
            $this->query .= " WHERE " . implode(' ', $conds);
        }

        $this->query .= !empty($this->orderBy) ? " ORDER BY {$this->orderBy}" : '';
        $this->query .= !empty($this->limit) ? " LIMIT {$this->limit}" : '';
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $paramName => $value) { $this->stmt->bindValue($paramName, $value); }
        $this->stmt->execute();

        // cleanup
        $this->wheres = [];
        $this->limit = "ALL";

        return $this->stmt->fetchAll(\PDO::FETCH_FUNC, function ($json) {
            return json_decode($json, true);
        });
    }

    public function first(): self
    {
        $this->limit = 1;
        return $this;
    }


}

/**
 * Sql class extending GenericSql.
 *
 * This class inherits 1:1 functionality from the GenericSql class. It is provided
 * as a default to interact with databases.
 */

class Sql2 extends GenericSql2 {}


class TsSql2 extends Sql2 {
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

}