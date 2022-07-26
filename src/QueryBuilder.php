<?php
declare(strict_types=1);
namespace Glued\Lib;


/**
 * Class QueryBuilder
 */
class QueryBuilder
{
    public static function select(string ...$select): Select
    {
        return new QuerySelect($select);
    }

    public static function insert(string $into): Insert
    {
        return new QueryInsert($into);
    }

    public static function update(string $table): Update
    {
        return new QueryUpdate($table);
    }

    public static function delete(string $table): Delete
    {
        return new QueryDelete($table);
    }
}
