<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query;

use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\PanelDefinition;
use Doctrine\DBAL\Connection;

final readonly class DistributionPanelQuery
{
    public function __construct(
        private Connection $connection,
        private SqlFilterBuilder $sqlFilterBuilder,
    ) {
    }

    /**
     * @return list<array{dimension_key: int, group_key: int|null, value: int}>
     */
    public function fetchDistribution(
        PanelDefinition $panel,
        FilterState $filterState,
        ?string $groupByField = null,
    ): array {
        $dimensionField = $panel->dimensionField;
        if (!\is_string($groupByField) || '' === $groupByField) {
            $groupByField = null;
        }

        $filter = $this->sqlFilterBuilder->buildWhere($filterState, $panel);
        $sql = 'SELECT '.$dimensionField.' AS dimension_key, '
            .($groupByField ?? 'NULL').' AS group_key, COUNT(*) AS value '
            .'FROM allocation_stats_projection'
            .$filter['where']
            .' GROUP BY '.$dimensionField.(null === $groupByField ? '' : ', '.$groupByField)
            .' ORDER BY '.$dimensionField.(null === $groupByField ? '' : ', '.$groupByField);

        /** @var list<array{dimension_key: mixed, group_key: mixed, value: mixed}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $filter['params'], $filter['types']);

        $out = [];
        foreach ($rows as $row) {
            $groupRaw = $row['group_key'];
            $out[] = [
                'dimension_key' => (int) $row['dimension_key'],
                'group_key' => null === $groupRaw || '' === $groupRaw ? null : (int) $groupRaw,
                'value' => (int) $row['value'],
            ];
        }

        return $out;
    }
}
