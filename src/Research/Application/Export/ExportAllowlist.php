<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * What an export query may read (ADR 045). The list lives in code and changes only by pull request. The check with
 * EXPLAIN is a plausibility check, not an enforced limit; #1636 makes the limit technical.
 */
final class ExportAllowlist
{
    /** Allocations and their reference tables. MCI cases and closures stay out (#1637). */
    public const array TABLES = [
        'allocation', 'hospital', 'dispatch_area', 'state', 'district', 'speciality', 'department', 'occasion',
        'assignment', 'infection', 'secondary_transport', 'assessment', 'indication_raw', 'indication_normalized',
        'indication_group', 'indication_group_indication_normalized',
    ];

    /** Columns no query may touch, not even in a filter. */
    public const array BLOCKED_COLUMNS = [
        'allocation' => ['notes', 'case_id_hash', 'import_id', 'public_id'],
        'hospital' => [
            'name', 'address_street', 'address_postal_code', 'address_city', 'address_state', 'address_country',
            'latitude', 'longitude', 'beds', 'participating_since', 'created_at', 'updated_at', 'public_id',
        ],
        'indication_raw' => ['review_comment'],
    ];

    /** References to user accounts are blocked in every table. */
    public const array BLOCKED_USER_COLUMNS = ['owner_id', 'created_by_id', 'updated_by_id', 'reviewed_by_id', 'first_matched_by_id'];

    /** Columns a query may join and count with, but never return as such. */
    public const array INTERNAL_ONLY = [
        'allocation' => ['id', 'hospital_id'],
        'hospital' => ['id'],
    ];

    /**
     * Functions that run SQL of their own, read files or settings, or reach other databases. EXPLAIN cannot see what
     * they read, so a query that calls one is refused.
     */
    public const string DENIED_FUNCTIONS = '/\b(query_to_\w+|table_to_\w+|schema_to_\w+|database_to_\w+|cursor_to_\w+|dblink\w*|pg_\w+|lo_\w+|current_setting|set_config|copy|xmlparse)\s*\(/i';

    public static function isBlocked(string $table, string $column): bool
    {
        return \in_array($column, self::BLOCKED_COLUMNS[$table] ?? [], true)
            || \in_array($column, self::BLOCKED_USER_COLUMNS, true);
    }

    public static function isInternalOnly(string $table, string $column): bool
    {
        return \in_array($column, self::INTERNAL_ONLY[$table] ?? [], true);
    }
}
