<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\Application\TopDiagnosesQuery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class OverviewIndicationTrendQuery
{
    public function __construct(
        private Connection $connection,
        private TopDiagnosesQuery $topDiagnosesQuery,
        private StatisticsScopeResolver $scopeResolver,
    ) {
    }

    /**
     * @return array{labels: list<string>, series: list<array{name: string, values: list<int>}>}
     */
    public function fetch(StatisticsContext $context, int $topLimit): array
    {
        $top = $this->topDiagnosesQuery->fetch($context, $topLimit);
        $indicationIds = [];
        $names = [];
        foreach ($top['rows'] as $row) {
            if (isset($row['indicationId'])) {
                $indicationIds[] = $row['indicationId'];
                $names[$row['indicationId']] = $row['label'];
            }
        }

        if ([] === $indicationIds) {
            return ['labels' => [], 'series' => []];
        }

        $bounds = StatisticsPeriodResolver::resolve($context->filter);
        $scope = $this->scopeResolver->resolveCriteria($context);
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return ['labels' => [], 'series' => []];
        }

        $criteria = OverviewQueryCriteria::fromPeriodBounds($bounds, $scope->hospitalIds);
        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);
        $types = ['indication_ids' => ArrayParameterType::INTEGER];
        $params['indication_ids'] = $indicationIds;

        $sql = <<<SQL
SELECT
    indication_normalized_id::int AS indication_id,
    created_year::int AS year,
    created_month::int AS month,
    COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
  AND indication_normalized_id IN (:indication_ids)
GROUP BY indication_normalized_id, created_year, created_month
ORDER BY created_year, created_month
SQL;

        /** @var list<array{indication_id:int,year:int,month:int,count:int}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $monthKeys = [];
        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', $row['year'], $row['month']);
            $monthKeys[$key] = true;
        }
        ksort($monthKeys);
        $labels = array_keys($monthKeys);

        $series = [];
        foreach ($indicationIds as $indicationId) {
            $valuesByMonth = array_fill_keys($labels, 0);
            foreach ($rows as $row) {
                if ($row['indication_id'] !== $indicationId) {
                    continue;
                }
                $key = sprintf('%04d-%02d', $row['year'], $row['month']);
                $valuesByMonth[$key] = $row['count'];
            }

            $series[] = [
                'name' => $names[$indicationId] ?? (string) $indicationId,
                'values' => array_values($valuesByMonth),
            ];
        }

        $displayLabels = array_map(
            static fn (string $key): string => new \DateTimeImmutable($key.'-01')->format('M y'),
            $labels,
        );

        return [
            'labels' => $displayLabels,
            'series' => $series,
        ];
    }
}
