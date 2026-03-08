<?php
use Ramsey\Uuid\Uuid;

declare(strict_types=1);
namespace Glued\Lib\Classes\Sql2;

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
