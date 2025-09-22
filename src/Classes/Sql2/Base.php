<?php

declare(strict_types=1);
namespace Glued\Lib\Classes\Sql2;

use \PDO;
use Ramsey\Uuid\Uuid;
use Rs\Json\Merge\Patch as JsonMergePatch;

/*

-- =========================
-- MUTABLE (upsert by uuid)
-- =========================

DROP TABLE IF EXISTS glued.mutable_doc CASCADE;
CREATE TABLE glued.mutable_doc (
  uuid     uuid  GENERATED ALWAYS AS ((doc->>'uuid')::uuid) STORED NOT NULL,
  version  uuid  DEFAULT uuidv7() NOT NULL,
  doc      jsonb NOT NULL,
  meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
  nonce    bytea GENERATED ALWAYS AS (decode(md5((doc - 'uuid')::text), 'hex')) STORED,
  iat      bigint DEFAULT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint NOT NULL, -- inserted/issued at
  uat      bigint DEFAULT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint NOT NULL, -- updated at (set in UPDATE)
  dat      bigint,                                                                         -- deleted at (soft-delete)
  sat      text,                                                                           -- raw source timestamp (as-is string)
  PRIMARY KEY (uuid)
);

-- Idempotency: one active row per content (allows tombstoned duplicates)
CREATE INDEX IF NOT EXISTS mutable_doc_iat_desc ON glued.mutable_doc (iat DESC);
CREATE INDEX IF NOT EXISTS mutable_doc_uat_desc ON glued.mutable_doc (uat DESC);


-- =========================
-- LOGGED (append only)
-- =========================

DROP TABLE IF EXISTS glued.logged_doc CASCADE;
CREATE TABLE glued.logged_doc (
  uuid     uuid  NOT NULL,
  version  uuid  DEFAULT uuidv7() NOT NULL,
  doc      jsonb NOT NULL,
  meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
  nonce    bytea GENERATED ALWAYS AS (decode(md5((doc - 'uuid')::text), 'hex')) STORED,
  iat      bigint DEFAULT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint NOT NULL, -- append time (ms)
  uat      bigint GENERATED ALWAYS AS (iat) VIRTUAL NOT NULL,
  dat      bigint,
  sat      text,
  period int8range GENERATED ALWAYS AS ( int8range(
              COALESCE((meta->>'nbf')::bigint, iat),
              COALESCE(LEAST(dat, (meta->>'exp')::bigint), dat, (meta->>'exp')::bigint),
              '[)'
         ) ) VIRTUAL,
  PRIMARY KEY (version)
);

CREATE INDEX IF NOT EXISTS logged_doc_uuid_iat_desc ON glued.logged_doc (uuid, iat DESC);


-- =========================
-- EXTERNAL INGEST LOG (raw append log)
-- =========================
DROP TABLE IF EXISTS ingest CASCADE;
CREATE TABLE ingest_log (
  ext_id   text NOT NULL,
  uuid     uuid DEFAULT gen_random_uuid() NOT NULL,  -- raw row id
  version  uuid DEFAULT uuidv7() NOT NULL,           -- time-sortable tie-break
  doc      jsonb NOT NULL,
  meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
  nonce    bytea GENERATED ALWAYS AS (decode(md5((doc::text)), 'hex')) STORED,
  iat      bigint DEFAULT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint NOT NULL,
  uat      bigint GENERATED ALWAYS AS ( iat ) VIRTUAL NOT NULL,
  dat      bigint DEFAULT NULL,
  sat      text,
  period int8range GENERATED ALWAYS AS ( int8range(
              COALESCE((meta->>'nbf')::bigint, iat),
              COALESCE(LEAST(dat, (meta->>'exp')::bigint), dat, (meta->>'exp')::bigint),
              '[)'
         ) ) VIRTUAL,
  PRIMARY KEY (uuid)
);

CREATE INDEX ingest_log_ext_iat_ver_desc ON ingest_log (ext_id, iat DESC, version DESC);
CREATE INDEX ingest_log_nonce_iat        ON ingest_log (nonce, iat);


-- ====================================================
   VERSIONED EXTERNAL INGEST (stable v5 uuid per ext_id + vers)
-- ====================================================
DROP TABLE IF EXISTS ingest_changelog CASCADE;
CREATE TABLE ingest_log (
  ext_id   text NOT NULL,
  uuid     uuid NOT NULL,                           -- v5 (table/source, ext_id) from app
  version  uuid DEFAULT uuidv7() NOT NULL,          -- per-version id
  doc      jsonb NOT NULL,
  meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
  nonce    bytea GENERATED ALWAYS AS (decode(md5((doc::text)), 'hex')) STORED,
  iat      bigint DEFAULT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint NOT NULL, -- unix time (milliseconds)
  uat      bigint DEFAULT (EXTRACT(EPOCH FROM clock_timestamp()) * 1000)::bigint NOT NULL, -- unix time (milliseconds)
  dat      bigint, -- unix time (milliseconds)
  sat      text, -- raw source at time
  period int8range GENERATED ALWAYS AS ( int8range(
              COALESCE((meta->>'nbf')::bigint, iat),
              COALESCE(LEAST(dat, (meta->>'exp')::bigint), dat, (meta->>'exp')::bigint),
              '[)'
         ) ) VIRTUAL,
  PRIMARY KEY (uuid, version)
);

-- Same-content dedupe per stream
CREATE UNIQUE INDEX icl_uuid_nonce ON ingest_changelog (uuid, nonce);

-- Ordering indexes
CREATE INDEX icl_ext_uat_ver_desc ON ingest_changelog (ext_id, uat DESC, version DESC);

-- Temporal integrity constraint
ALTER TABLE ingest_changelog ADD CONSTRAINT icl_no_overlap UNIQUE (uuid, period WITHOUT OVERLAPS);

*/


/**
 * Utilities for stable UUID v5 derivation (stable per ext_id) and general helpers.
 */
final class UuidTools
{
    /**
     * Build a namespace UUID from a logical stream/table name.
     */
    public static function tableNamespace(string $table): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_DNS, "glued:$table")->toString();
    }

    /**
     * Stable UUID v5 for an external id, namespaced by the logical table.
     */
    public static function stableForExtId(string $table, string $extId): string
    {
        $ns = Uuid::fromString(self::tableNamespace($table));
        return Uuid::uuid5($ns, $extId)->toString();
    }

    /**
     * Stable UUID v5 for ext id, namespaced by (table + source).
     */
    public static function stableForSource(string $table, string $source, string $extId): string
    {
        $ns = Uuid::uuid5(Uuid::NAMESPACE_DNS, "glued:$table:$source");
        return Uuid::uuid5($ns, $extId)->toString();
    }
}

/**
 * Base class for glued postgresql 18 :
 *
 *  Table contract (required columns):
 *  - {uuidCol}  UUID PRIMARY KEY
 *  - {docCol}   JSONB  -- primary payload
 *  - {metaCol}  JSONB  -- {schemaVer, nbf, exp, actorId, actorIp, sourceName, sourceUuid, ...}
 *  - iat/uat/dat TIMESTAMPTZ
 *  - sat TEXT
 *
 * Optional generated columns:
 *  - nbf/exp GENERATED (virtual) TIMESTAMPTZ (DB-side)
 *
 *  Envelope read model:
 *    SELECT doc || jsonb_build_object('meta', meta, 'iat', iat, 'uat', uat, 'dat', dat, 'sat', sat, 'nbf', nbf, 'exp', exp)
 *    -- keys on the right overwrite same keys in `doc`.
 */
abstract class Base
{
    /** @var \PDO PDO instance for database connection. */
    public $pdo;

    /** @var \PDOStatement PDOStatement instance for database queries. */
    public $stmt;

    /** @var string Schema name for database tables. */
    protected string $schema = 'glued';

    /** @var string Table name for database queries. */
    protected string $table;

    /** @var string Document UUID column. */
    protected string $uuidCol = 'uuid';

    /** @var string Document version UUID column. */
    protected string  $versionCol = 'version';

    /** @var string JSONB document (primary payload). */
    protected string $docCol  = 'doc';

    /** @var string JSONB with provenance and audit data about $docCol. */
    protected string $metaCol = 'meta';

    /** @var int JSON encoding flags for payload/meta serialization. */
    protected int $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE;

    /** @var array Builder state: array of WHERE conditions */
    public array $wheres = [ ['column'=>'dat','op'=>'IS NULL','value'=>null,'logical'=>'AND'] ];

    /** @var string|int Builder state: limit condition */
    public string|int $limit = 'ALL';

    /** @var ?string Builder state: orderBy condition */
    public ?string $orderBy = null;

    /** @var string Builder state: A select modifier */
    public string $selectModifier = "";

    /** @var string Builder state: full query observability */
    public string $query = "";

    /** @var array Builder state: query parameters observability */
    public array $params = [];

    /** @var string Builder state: full query observability */
    public string $lastQueryString = "";

    /** @var array Builder state: query parameters observability */
    public array $lastQueryParams = [];

    public function __construct(PDO $pdo, string $table, ?string $schema = 'glued')
    {
        $this->pdo = $pdo;
        $this->table = $table;
        if ($schema) $this->schema = $schema;
    }

    /**
     * WHERE chain builder (column op :param).
     *
     * @param string $column  Column name or expression (e.g., "uuid", "iat")
     * @param string $op      SQL operator (e.g., '=', '>', '<', 'LIKE', '@>')
     * @param mixed  $value   Bound value to compare against
     * @param string $logical 'AND' or 'OR' logical operator to bind a condition with previous ones ('AND' is default)
     */
    public function where(string $column, string $op, mixed $value, string $logical = 'AND'): self
    {
        $this->wheres[] = compact('column', 'op', 'value', 'logical');
        return $this;
    }

    /**
     * Build the SQL WHERE clause from $this->wheres and populate $this->params.
     *
     * Input shape ($this->wheres):
     * [
     *   [
     *     'column'  => string,          // column or SQL expression
     *     'op'      => string,          // '=', '>', '<', 'LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL', ...
     *     'value'   => mixed|null,      // scalar for scalar ops; array for IN/NOT IN; null for IS NULL ops
     *     'logical' => 'AND'|'OR',      // chain operator (ignored for the first; first is coerced to AND)
     *   ],
     *   ...
     * ]
     *
     * Rules:
     * - Starts with 'WHERE TRUE' so every predicate can be added uniformly with a leading logical operator.
     * - The first predicate is always joined using AND (prevents 'WHERE TRUE OR (...)' tautologies).
     * - 'IS NULL' / 'IS NOT NULL' → no bound parameters.
     * - 'IN' / 'NOT IN' with array:
     *     • Expands to (:p{i}_0, :p{i}_1, ...) and binds each element.
     *     • Empty array short-circuits (FALSE for IN, TRUE for NOT IN).
     * - All other operators bind a single scalar to :p{i}.
     *
     * Side effects:
     * - Fills $this->params with placeholders → values for PDO binding.
     *
     * @return string Leading ' WHERE TRUE ...' (even when there are zero predicates; PostgreSQL optimizes it away).
     */

    private function whereBuilder(): string
    {
        $sql = ' WHERE TRUE'; // Seed with a tautology to simplify joining logic.

        foreach ($this->wheres as $i => $c) {
            // 0) Prepare. First operator is forced to AND due to tautology above, subsequent operators as provided
            $join = $i ? " {$c['logical']} " : ' AND ';
            $col = $c['column'];
            $op  = strtoupper(trim((string)$c['op']));
            $val = $c['value'];

            // 1) NULL checks require no bound value.
            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $sql .= $join . "($col $op)";
                continue;
            }

            // 2) IN / NOT IN over arrays → expand and bind each element.
            if (($op === 'IN' || $op === 'NOT IN') && is_array($val)) {

                // Empty list: avoid invalid 'IN ()' by short-circuiting to a constant.
                if ($val === []) {
                    $sql .= $join . ($op === 'IN' ? '(FALSE)' : '(TRUE)');
                    continue;
                }
                // Build placeholders :p{i}_k and bind each array element.
                $phs = [];
                foreach (array_values($val) as $k => $v) {
                    $p = ":p{$i}_{$k}";
                    $phs[] = $p;
                    $this->params[$p] = $v; // register binding
                }
                $sql .= $join . "($col $op (" . implode(',', $phs) . "))";
                continue;
            }

            // 3) Generic scalar operator: bind a single placeholder :p{i}.
            $p = ":p{$i}";
            $this->params[$p] = $val; // register binding
            $sql .= $join . "($col $op $p)";
        }
        return $sql;
    }

    /** Chain ORDER BY. */
    public function orderBy(string $expr): self { $this->orderBy = $expr; return $this; }

    /** Chain LIMIT 1. */
    public function first(): self { $this->limit = 1; return $this; }

    /** Reset builder state after a SELECT. */
    protected function reset(): void {
        $this->lastQueryString = $this->query;
        $this->lastQueryParams = $this->params;
        $this->wheres = [ ['column'=>'dat','op'=>'IS NULL','value'=>null,'logical'=>'AND'] ];
        $this->limit = 'ALL';
        $this->orderBy = null;
        $this->query = '';
        $this->params = [];
        $this->selectModifier = '';
    }

    /**
     * Normalize doc/meta, ensuring UUID in doc if provided.
     *
     * @param array|object $doc
     * @param array|object $meta
     * @param string|null  $forceUuid  If non-null, set doc['uuid']=forceUuid
     * @return array [array $doc, array $meta]
     */
    protected function normalize(array|object $doc, array|object $meta = [], ?string $forceUuid = null): array
    {
        $d = (array)$doc;
        if ($forceUuid !== null) $d['uuid'] = $forceUuid;
        $m = (array)$meta;
        return [$d, $m];
    }

    /**
     * Envelope select: doc || the system+meta columns for convenient reads.
     */
    protected function selectEnvelope(): string
    {
        return "{$this->selectModifier} {$this->docCol} || jsonb_build_object(
            'meta', {$this->metaCol},
            'iat', iat,
            'uat', uat,
            'sat', sat,
            'version', version,
            'nonce', encode(nonce, 'hex')
        )";
    }

    /**
     * Fetch one by UUID (merged envelope).
     */
    public function get(string $uuid): ?array
    {
        // Deterministic: newest by iat, then by version (in case of same iat)
        $order = $this->orderBy ?? "iat DESC, {$this->versionCol} DESC";
        $this->query = "SELECT {$this->selectEnvelope()}
                    FROM {$this->schema}.{$this->table}
                    WHERE {$this->uuidCol} = :u
                    ORDER BY {$order}
                    LIMIT 1";
        $this->params = [':u' => $uuid];
        $this->stmt = $this->pdo->prepare($this->query);
        $this->stmt->bindValue(':u', $uuid);
        $this->stmt->execute();
        $row = $this->stmt->fetchColumn();
        $this->reset();
        return $row ? json_decode($row, true) : null;
    }



    /**
     * Fetch many with chainable predicates (merged envelopes).
     * Use ->orderBy('iat DESC')->first() to emulate "latest".
     */
    public function getAll(): array
    {
        $this->params = [];
        $this->query  = "SELECT {$this->selectEnvelope()} FROM {$this->schema}.{$this->table} t";
        $this->query .= $this->whereBuilder();
        if ($this->orderBy) $this->query .= " ORDER BY {$this->orderBy}";
        if ($this->limit !== 'ALL') $this->query .= " LIMIT {$this->limit}";

        // Prepare, bind, execute, decode each json into an array
        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) { $this->stmt->bindValue($k, $v); }
        $this->stmt->execute();
        $this->reset();
        return $this->stmt->fetchAll(PDO::FETCH_FUNC, fn(string $json) => json_decode($json, true));
    }


}


