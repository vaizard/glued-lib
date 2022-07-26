<?php
declare(strict_types=1);
namespace Glued\Lib;
use Glued\Lib\QueryInterface;

class QueryDelete implements QueryInterface
{
    /**
     * @var string
     */
    private $table;

    /**
     * @var array<string>
     */
    private $conditions = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function __toString(): string
    {
        return 'DELETE FROM ' . $this->table . ($this->conditions === [] ? '' : ' WHERE ' . implode(' AND ', $this->conditions));
    }

    public function where(string ...$where): self
    {
        foreach ($where as $arg) {
            $this->conditions[] = $arg;
        }
        return $this;
    }
}