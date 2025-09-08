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
    uuid     uuid GENERATED ALWAYS AS ((doc->>'uuid')::uuid) STORED NOT NULL,
    version  uuid DEFAULT uuidv7() NOT NULL,      -- monotonic-ish ids for server-side audit chains
    doc      jsonb NOT NULL,
    meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
    nonce    bytea GENERATED ALWAYS AS (decode(md5((doc - 'uuid')::text), 'hex')) STORED,
    iat      timestamptz DEFAULT now() NOT NULL,  -- inserted/issued at
    uat      timestamptz DEFAULT now() NOT NULL,  -- updated at (set in UPDATE)
    dat      timestamptz,                         -- deleted at (soft-delete)
    sat      text,                                -- raw source timestamp (as-is string)
    PRIMARY KEY (uuid)
);
CREATE INDEX mutable_doc_iat_desc ON glued.mutable_doc (iat DESC);
CREATE INDEX mutable_doc_uat_desc ON glued.mutable_doc (uat DESC);

-- =========================
-- LOGGED (append only)
-- =========================

DROP TABLE IF EXISTS glued.logged_doc CASCADE;
CREATE TABLE glued.logged_doc (
    uuid     uuid NOT NULL,
    version  uuid DEFAULT uuidv7() NOT NULL,
    doc      jsonb NOT NULL,
    meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
    nonce    bytea GENERATED ALWAYS AS (decode(md5((doc - 'uuid')::text), 'hex')) STORED,
    iat      timestamptz DEFAULT now() NOT NULL,              -- log append time
    uat      timestamptz GENERATED ALWAYS AS (iat) VIRTUAL,   -- same as iat
    dat      timestamptz,                                     -- tombstone time (soft delete)
    sat      text,
    nbf      timestamptz,
    exp      timestamptz,
    period   tstzrange  GENERATED ALWAYS AS (tstzrange(COALESCE(nbf, iat), COALESCE(exp, 'infinity'::timestamptz), '[)')) VIRTUAL,
    PRIMARY KEY (version)
);

CREATE INDEX        logged_doc_uuid_iat_desc ON glued.logged_doc (uuid, iat DESC);

-- Optional temporal integrity if you *actively* manage meta.exp:
-- ALTER TABLE glued.logged_doc
--   ADD CONSTRAINT logged_doc_no_overlap UNIQUE (uuid, period WITHOUT OVERLAPS);  -- [PG18]

-- Optional DB-level append-only (no UPDATE/DELETE)
ALTER TABLE glued.logged_doc ENABLE ROW LEVEL SECURITY;
REVOKE UPDATE, DELETE ON glued.logged_doc FROM PUBLIC;
CREATE POLICY logged_insert_only ON glued.logged_doc FOR INSERT WITH CHECK (true);


-- =========================
-- INGEST (raw append log)
-- =========================
DROP TABLE IF EXISTS glued.ingest CASCADE;
CREATE TABLE glued.ingest (
  uuid     uuid DEFAULT gen_random_uuid() NOT NULL,
  version  uuid GENERATED ALWAYS AS (uuid) STORED NOT NULL,  -- identical to uuid
  doc      jsonb NOT NULL,
  meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
  nonce    bytea GENERATED ALWAYS AS (decode(md5((doc::text)), 'hex')) STORED,
  iat      timestamptz DEFAULT now() NOT NULL,
  sat      text,
  ext_id   text NOT NULL,

  nbf      timestamptz GENERATED ALWAYS AS (CASE WHEN meta ? 'nbf' THEN (meta->>'nbf')::timestamptz END) VIRTUAL,
  exp      timestamptz GENERATED ALWAYS AS (CASE WHEN meta ? 'exp' THEN (meta->>'exp')::timestamptz END) VIRTUAL,
  period   tstzrange  GENERATED ALWAYS AS (tstzrange(COALESCE(nbf, iat), COALESCE(exp, 'infinity'::timestamptz), '[)')) VIRTUAL,

  PRIMARY KEY (nonce, iat)
);
CREATE INDEX ingest_ext_iat_desc ON glued.ingest (ext_id, iat DESC);



-- ====================================================
   EXTERNAL INGEST (stable v5 uuid per ext_id + vers)
-- ====================================================
DROP TABLE IF EXISTS glued.external_ingest_log CASCADE;
CREATE TABLE glued.external_ingest_log (
  ext_id   text NOT NULL,
  uuid     uuid NOT NULL,                      -- stable v5 from (table/source, ext_id) – set in app
  version  uuid DEFAULT uuidv7() NOT NULL,     -- per-version id [PG18]
  doc      jsonb NOT NULL,
  meta     jsonb NOT NULL DEFAULT '{}'::jsonb,
  nonce    bytea GENERATED ALWAYS AS (decode(md5((doc::text)), 'hex')) STORED,

  iat      timestamptz DEFAULT now() NOT NULL,
  sat      text,

  nbf      timestamptz GENERATED ALWAYS AS (CASE WHEN meta ? 'nbf' THEN (meta->>'nbf')::timestamptz END) VIRTUAL,
  exp      timestamptz GENERATED ALWAYS AS (CASE WHEN meta ? 'exp' THEN (meta->>'exp')::timestamptz END) VIRTUAL,
  period   tstzrange  GENERATED ALWAYS AS (tstzrange(COALESCE(nbf, iat), COALESCE(exp, 'infinity'::timestamptz), '[)')) VIRTUAL,

  PRIMARY KEY (uuid, version)
);
CREATE UNIQUE INDEX eil_uuid_nonce  ON glued.external_ingest_log (uuid, nonce);
CREATE INDEX        eil_ext_iat_desc ON glued.external_ingest_log (ext_id, iat DESC);

-- Optional temporal integrity if you set exp:
-- ALTER TABLE glued.external_ingest_log
--   ADD CONSTRAINT eil_no_overlap UNIQUE (uuid, period WITHOUT OVERLAPS);  -- [PG18]

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
    public array $wheres = [ ['column'=>'dat','op'=>'IS NOT NULL','value'=>null,'logical'=>'AND'] ];

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
            'nonce', encode(nonce, 'hex')
        )";
    }

    /**
     * Fetch one by UUID (merged envelope).
     */
    public function get(string $uuid): ?array
    {
        $this->query = "SELECT {$this->selectEnvelope()}
                        FROM {$this->schema}.{$this->table}
                        WHERE {$this->uuidCol} = :u";
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
        $this->query = "SELECT {$this->selectEnvelope()} FROM {$this->schema}.{$this->table} t";
        $this->params = [];
        if ($this->wheres) {
            $chunks = [];
            foreach ($this->wheres as $i => $c) {
                $p = ":p{$i}";
                $chunks[] = ($i ? " {$c['logical']} " : '') . "({$c['column']} {$c['op']} {$p})";
                $this->params[$p] = $c['value'];
            }
            $this->query .= ' WHERE ' . implode('', $chunks);
        }
        if ($this->orderBy) $this->query .= " ORDER BY {$this->orderBy}";
        if ($this->limit !== 'ALL') $this->query .= " LIMIT {$this->limit}";

        $this->stmt = $this->pdo->prepare($this->query);
        foreach ($this->params as $k => $v) $this->stmt->bindValue($k, $v);
        $this->stmt->execute();
        $this->reset();
        return $this->stmt->fetchAll(PDO::FETCH_FUNC, fn(string $json) => json_decode($json, true));
    }
}


