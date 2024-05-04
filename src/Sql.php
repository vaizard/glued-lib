<?php

declare(strict_types=1);
namespace Glued\Lib;

use PDO;

class QueryBuilder
{
    protected $pdo;
    protected $table;
    protected $schema;
    protected $idColumn;
    protected $dataColumn;
    protected $whereClauses = [];

    public function __construct(PDO $pdo, string $table, string $schema, string $idColumn, string $dataColumn)
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->schema = $schema;
        $this->idColumn = $idColumn;
        $this->dataColumn = $dataColumn;
    }

    public function where(string $column, string $condition, $value): self
    {
        $this->whereClauses[] = "$column $condition :$column";
        return $this;
    }

    public function execute(): array
    {
        $query = "SELECT {$this->dataColumn} FROM {$this->schema}.{$this->table}";
        if (!empty($this->whereClauses)) {
            $query .= " WHERE " . implode(" AND ", $this->whereClauses);
        }

        $stmt = $this->pdo->prepare($query);
        foreach ($this->whereClauses as $whereClause) {
            list($column, $condition) = explode(" ", $whereClause, 2);
            $stmt->bindParam(":{$column}", $value); // Assuming $value is predefined
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

abstract class Sql
{
    protected $pdo;
    protected $table;
    protected $schema = 'glued';
    protected $idColumn = 'uuid';
    protected $dataColumn = 'doc';

    public function __construct(PDO $pdo, string $table)
    {
        $this->pdo = $pdo;
        $this->table = $table;
    }

    protected function create($doc): string
    {
        $uuid = $doc['uuid'] ?? $this->uuid();
        $doc['uuid'] = $uuid;
        $doc = json_encode($doc);
        $stmt = $this->pdo->prepare("INSERT INTO {$this->schema}.{$this->table} ({$this->dataColumn}) VALUES (:doc)");
        $stmt->bindParam(':doc', $doc);
        $stmt->execute();
        return $uuid;
    }

    protected function createBatch(array $items): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO {$this->schema}.{$this->table} ({$this->dataColumn}) VALUES (:doc)");
        foreach ($items as $item) {
            $uuid = $doc['uuid'] ?? $this->uuid();
            $doc['uuid'] = $uuid;
            $doc = json_encode($doc);
            $stmt->bindParam(':doc', $doc);
            $stmt->execute();
        }
    }

    protected function get(string $uuid)
    {
        $stmt = $this->pdo->prepare("SELECT {$this->dataColumn} FROM {$this->schema}.{$this->table} WHERE {$this->idColumn} = :uuid");
        $stmt->bindParam(':uuid', $uuid);
        $stmt->execute();
        return $stmt->fetch();
    }

    protected function update(string $uuid, $body): void
    {
        $body->validateCorrectUUID($uuid);
        $toUpdate = $body->withUUID($uuid)->toJsonB();
        $stmt = $this->pdo->prepare("UPDATE {$this->schema}.{$this->table} SET {$this->dataColumn} = :doc WHERE {$this->idColumn} = :uuid");
        $stmt->bindParam(':doc', $toUpdate);
        $stmt->bindParam(':uuid', $uuid);
        $stmt->execute();
    }

    protected function delete(string $uuid): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->schema}.{$this->table} WHERE {$this->idColumn} = :uuid");
        $stmt->bindParam(':uuid', $uuid);
        $stmt->execute();
    }

    public function getAll(): QueryBuilder
    {
        return new QueryBuilder($this->pdo, $this->table, $this->schema, $this->idColumn, $this->dataColumn);
    }

}
