<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

/**
 * Single source of truth for “assignment despite closed department”.
 *
 * The flag is the imported IVENA boolean {@see Allocation::$departmentWasClosed},
 * copied 1:1 onto {@see allocation_stats_projection.department_was_closed}.
 * Null projection values are not treated as closed.
 */
final class DepartmentWasClosedSql
{
    public const string CLOSED_PREDICATE = 'department_was_closed IS TRUE';

    public const string REGULAR_PREDICATE = 'department_was_closed IS NOT TRUE';

    public static function closed(?string $tableAlias = null): string
    {
        return self::qualify(self::CLOSED_PREDICATE, $tableAlias);
    }

    public static function regular(?string $tableAlias = null): string
    {
        return self::qualify(self::REGULAR_PREDICATE, $tableAlias);
    }

    private static function qualify(string $predicate, ?string $tableAlias): string
    {
        if (null === $tableAlias || '' === $tableAlias) {
            return $predicate;
        }

        return str_replace('department_was_closed', $tableAlias.'.department_was_closed', $predicate);
    }
}
