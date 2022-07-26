<?php
declare(strict_types=1);
namespace Glued\Lib;


/**
 * Class QueryBuilder
 */
class QueryBuilder
{
    public static function select(string $query): QuerySelect
    {
        return new QuerySelect($query);
    }

    public static function insert(string $into): QueryInsert
    {
        return new QueryInsert($into);
    }

    public static function update(string $table): QueryUpdate
    {
        return new QueryUpdate($table);
    }

    public static function delete(string $table): QueryDelete
    {
        return new QueryDelete($table);
    }
}
