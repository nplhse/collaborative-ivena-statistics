<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use Doctrine\DBAL\Connection;

final readonly class OverviewTopEntitiesBatchQuery
{
    public const string DIMENSION_SPECIALITY = 'speciality';
    public const string DIMENSION_DEPARTMENT = 'department';
    public const string DIMENSION_ASSIGNMENT = 'assignment';
    public const string DIMENSION_OCCASION = 'occasion';
    public const string DIMENSION_INFECTION = 'infection';
    public const string DIMENSION_SECONDARY_DIAGNOSIS = 'secondary_diagnosis';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, list<array{label: string, count: int}>>
     */
    public function __invoke(OverviewQueryCriteria $criteria, int $limitPerDimension): array
    {
        if ($criteria->hasEmptyHospitalScope()) {
            return $this->emptyResult();
        }

        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);

        $sql = <<<SQL
WITH base AS (
    SELECT
        speciality_id,
        department_id,
        assignment_id,
        occasion_id,
        infection_id,
        secondary_indication_normalized_id
    FROM allocation_stats_projection
    WHERE {$where}
)
(
    SELECT :dim_speciality AS dimension, COALESCE(s.name, 'Unknown') AS label, COUNT(*)::int AS cnt
    FROM base b
    LEFT JOIN speciality s ON s.id = b.speciality_id
    GROUP BY s.name
    ORDER BY 3 DESC
    LIMIT {$limitPerDimension}
)
UNION ALL
(
    SELECT :dim_department, COALESCE(d.name, 'Unknown'), COUNT(*)::int
    FROM base b
    LEFT JOIN department d ON d.id = b.department_id
    GROUP BY d.name
    ORDER BY 3 DESC
    LIMIT {$limitPerDimension}
)
UNION ALL
(
    SELECT :dim_assignment, COALESCE(a.name, 'Unknown'), COUNT(*)::int
    FROM base b
    LEFT JOIN assignment a ON a.id = b.assignment_id
    GROUP BY a.name
    ORDER BY 3 DESC
    LIMIT {$limitPerDimension}
)
UNION ALL
(
    SELECT :dim_occasion, COALESCE(o.name, 'Unknown'), COUNT(*)::int
    FROM base b
    LEFT JOIN occasion o ON o.id = b.occasion_id
    GROUP BY o.name
    ORDER BY 3 DESC
    LIMIT {$limitPerDimension}
)
UNION ALL
(
    SELECT :dim_infection, COALESCE(i.name, 'Unknown'), COUNT(*)::int
    FROM base b
    LEFT JOIN infection i ON i.id = b.infection_id
    GROUP BY i.name
    ORDER BY 3 DESC
    LIMIT {$limitPerDimension}
)
UNION ALL
(
    SELECT :dim_secondary, COALESCE(n.name, 'Unknown'), COUNT(*)::int
    FROM base b
    LEFT JOIN indication_normalized n ON n.id = b.secondary_indication_normalized_id
    GROUP BY n.name
    ORDER BY 3 DESC
    LIMIT {$limitPerDimension}
)
SQL;

        $params['dim_speciality'] = self::DIMENSION_SPECIALITY;
        $params['dim_department'] = self::DIMENSION_DEPARTMENT;
        $params['dim_assignment'] = self::DIMENSION_ASSIGNMENT;
        $params['dim_occasion'] = self::DIMENSION_OCCASION;
        $params['dim_infection'] = self::DIMENSION_INFECTION;
        $params['dim_secondary'] = self::DIMENSION_SECONDARY_DIAGNOSIS;

        /** @var list<array{dimension: string, label: string, cnt: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $result = $this->emptyResult();
        foreach ($rows as $row) {
            $dimension = $row['dimension'];
            $result[$dimension][] = [
                'label' => $row['label'],
                'count' => (int) $row['cnt'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, list<array{label: string, count: int}>>
     */
    private function emptyResult(): array
    {
        return [
            self::DIMENSION_SPECIALITY => [],
            self::DIMENSION_DEPARTMENT => [],
            self::DIMENSION_ASSIGNMENT => [],
            self::DIMENSION_OCCASION => [],
            self::DIMENSION_INFECTION => [],
            self::DIMENSION_SECONDARY_DIAGNOSIS => [],
        ];
    }
}
