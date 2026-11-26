<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * Minimum of contributing hospitals per group of an aggregated extract (ADR 045, #1540), the same number as for
 * comparisons across hospitals in the statistics.
 */
final class HospitalMinimum
{
    public const int MIN_HOSPITALS = 3;

    /**
     * Hospital IDs from the reserved column: a PostgreSQL array ("{1,2,3}") for aggregated queries, one ID for single
     * cases.
     *
     * @return list<int>
     */
    public static function parse(mixed $value): array
    {
        if (\is_int($value)) {
            return [$value];
        }

        if (!\is_string($value) || '' === trim($value, '{} ')) {
            return [];
        }

        return array_values(array_unique(array_map(intval(...), explode(',', trim($value, '{} ')))));
    }
}
