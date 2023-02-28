<?php
declare(strict_types=1);
namespace Glued\Lib;
use Glued\Lib\QueryInterface;

// $query_string = (new \Glued\Lib\QueryBuilder())->insert('table')->columns2( 'col1', 'col2')->upsert2('col2');
// use columns,upsert for named parameters, and columns2,upsert2 for unnamed parameters.
// TODO consider having only columns, not columns2 - use named vs. unnamed paramteres based on initialiyation of the QueryBuilder.

class QueryInsert implements QueryInterface
{
    /**
     * @var string
     */
    private $table;

    /**
     * @var array<string>
     */
    private $columns = [];


    /**
     * @var array<string>
     */
    private $columns2 = [];

    /**
     * @var array<string>
     */
    private $upsert = [];

    /**
     * @var array<string>
     */
    private $upsert2 = [];

    /**
     * @var array<string>
     */
    private $values = [];

    /**
     * @var array<string>
     */
    private $uvalues = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }


    public function __toString(): string
    {
        return 'INSERT INTO ' . $this->table
            . ($this->columns === [] ? '' : ' (' . implode(', ',$this->columns) . ') VALUES (' . implode(', ',$this->values) . ')')
            . ($this->columns2 === [] ? '' : ' (' . implode(', ',$this->columns2) . ') VALUES (' . implode(', ',$this->values) . ')')
            . ($this->upsert === [] ? '' : ' ON DUPLICATE KEY UPDATE (' . implode(', ',$this->upsert) . ') VALUES (' . implode(', ',$this->uvalues) . ')')
            . ($this->upsert2 === [] ? '' : ' ON DUPLICATE KEY UPDATE (' . implode(', ',$this->upsert2) . ') VALUES (' . implode(', ',$this->uvalues) . ')')
            ;
    }

    public function columns(string ...$columns): self
    {
        $columns = array_map(function ($i) { return "`" . $i . "`"; }, $columns);
        $this->columns = $columns;
        foreach ($columns as $column) {
            $this->values[] = ":$column";
        }
        return $this;
    }

    public function columns2(string ...$columns2): self
    {
        $columns2 = array_map(function ($i) { return "`" . $i . "`"; }, $columns2);
        $this->columns2 = $columns2;
        foreach ($columns2 as $column2) {
            $this->values[] = "?";
        }
        return $this;
    }

    public function upsert(string ...$upsert): self
    {
        $upsert = array_map(function ($i) { return "`" . $i . "`"; }, $upsert);
        $this->upsert = $upsert;
        foreach ($upsert as $column) {
            $this->uvalues[] = ":$column";
        }
        return $this;
    }

    public function upsert2(string ...$upsert2): self
    {
        $upsert2 = array_map(function ($i) { return "`" . $i . "`"; }, $upsert2);
        $this->upsert2 = $upsert2;
        foreach ($upsert2 as $column2) {
            $this->uvalues[] = "?";
        }
        return $this;
    }
}