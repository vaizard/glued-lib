<?php
declare(strict_types=1);
namespace Glued\Lib;
use Glued\Lib\QueryInterface;

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
    private $values = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function __toString(): string
    {
        return 'INSERT INTO ' . $this->table
            . ' (' . implode(', ',$this->columns) . ') VALUES (' . implode(', ',$this->values) . ')';
    }

    public function columns(string ...$columns): self
    {
        $this->columns = $columns;
        foreach ($columns as $column) {
            $this->values[] = ":$column";
        }
        return $this;
    }
}