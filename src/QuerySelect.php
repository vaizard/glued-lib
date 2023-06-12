<?php
declare(strict_types=1);
namespace Glued\Lib;
use Glued\Lib\QueryInterface;

class QuerySelect implements QueryInterface
{
    /**
     * @var string
     */
    private $query = "";

    /**
     * @var array<string>
     */
    private $conditions = [];

    /**
     * @var array<string>
     */
    private $hconditions = [];

    /**
     * @var array<string>
     */
    private $order = [];

    /**
     * @var array<string>
     */
    private $from = [];

    /**
     * @var array<string>
     */
    private $innerJoin = [];

    /**
     * @var array<string>
     */
    private $leftJoin = [];

    /**
     * @var int|null
     */
    private $limit;

    public function __construct(string $query = "")
    {
        $this->query = $query;
    }

    public function query($query): self
    {
        $this->query = $query;
        return $this;
    }

    public function __toString(): string
    {
        return 'SELECT FROM ' . $this->query
            . ($this->leftJoin === [] ? '' : ' LEFT JOIN '. implode(' LEFT JOIN ', $this->leftJoin))
            . ($this->innerJoin === [] ? '' : ' INNER JOIN '. implode(' INNER JOIN ', $this->innerJoin))
            . ($this->conditions === [] ? '' : ' WHERE ' . implode(' AND ', $this->conditions))
            . ($this->hconditions === [] ? '' : ' HAVING ' . implode(' AND ', $this->hconditions))
            . ($this->order === [] ? '' : ' ORDER BY ' . implode(', ', $this->order))
            . ($this->limit === null ? '' : ' LIMIT ' . $this->limit);
    }

    public function where(string ...$where): self
    {
        foreach ($where as $arg) {
            $this->conditions[] = $arg;
        }
        return $this;
    }

    public function having(string ...$having): self
    {
        foreach ($having as $arg) {
            $this->hconditions[] = $arg;
        }
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function orderBy(string ...$order): self
    {
        foreach ($order as $arg) {
            $this->order[] = $arg;
        }
        return $this;
    }

    public function innerJoin(string ...$join): self
    {
        $this->leftJoin = [];
        foreach ($join as $arg) {
            $this->innerJoin[] = $arg;
        }
        return $this;
    }

    public function leftJoin(string ...$join): self
    {
        $this->innerJoin = [];
        foreach ($join as $arg) {
            $this->leftJoin[] = $arg;
        }
        return $this;
    }
}
