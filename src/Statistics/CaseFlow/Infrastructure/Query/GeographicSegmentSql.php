<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\CaseFlow\Application\GeographicSegment\GeographicSegment;
use Doctrine\DBAL\ArrayParameterType;

/**
 * AND-combines a geographic segment onto an existing Case Flow WHERE clause.
 */
final class GeographicSegmentSql
{
    /**
     * @param array<string, mixed>              $params
     * @param array<string, ArrayParameterType> $types
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}
     */
    public static function append(
        string $where,
        array $params,
        array $types,
        ?GeographicSegment $segment,
        string $tableAlias = 'asp',
    ): array {
        if (!$segment instanceof GeographicSegment) {
            return [$where, $params, $types];
        }

        [$predicate, $segmentParams] = $segment->sqlPredicate($tableAlias);

        return [$where.' AND '.$predicate, [...$params, ...$segmentParams], $types];
    }
}
