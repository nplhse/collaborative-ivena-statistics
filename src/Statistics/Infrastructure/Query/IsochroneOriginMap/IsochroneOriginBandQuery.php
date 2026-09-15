<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IsochroneOriginMap;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginBandQueryResult;
use App\Statistics\Application\IsochroneOriginMap\IsochroneOriginBandQueryInterface;
use App\Statistics\Application\Mapping\DepartmentWasClosedSql;
use App\Statistics\Application\Mapping\IsochroneOriginBandSql;
use App\Statistics\Infrastructure\Query\IndicationDashboard\IndicationDashboardSqlFilter;
use App\Statistics\Infrastructure\Query\ProjectionDrawerFilterSql;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for IsochroneOriginBandQueryInterface. */
#[AsAlias(IsochroneOriginBandQueryInterface::class)]
final readonly class IsochroneOriginBandQuery implements IsochroneOriginBandQueryInterface
{
    public function __construct(
        private Connection $connection,
        private ProjectionDrawerFilterSql $drawerFilterSql,
    ) {
    }

    /**
     * @param list<int>|null $indicationIds
     */
    #[\Override]
    public function fetch(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
        StatisticsScopeCriteria $scope,
        ?array $indicationIds = null,
        ?bool $departmentWasClosed = null,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): IsochroneOriginBandQueryResult {
        if (\is_array($scope->hospitalIds) && [] === $scope->hospitalIds) {
            return IsochroneOriginBandQueryResult::empty();
        }

        if (\is_array($indicationIds) && [] === $indicationIds) {
            return IsochroneOriginBandQueryResult::empty();
        }

        [$where, $params, $types] = IndicationDashboardSqlFilter::buildScopePeriodWhere($from, $toExclusive, $scope);

        if (\is_array($indicationIds)) {
            $where .= ' AND indication_normalized_id IN (:indication_ids)';
            $params['indication_ids'] = array_map(static fn (int $id): int => $id, $indicationIds);
            $types['indication_ids'] = ArrayParameterType::INTEGER;
        }

        if ($drawerFilter instanceof StatisticsDrawerFilter && $drawerFilter->isActive()) {
            [$drawerConditions, $drawerParams] = $this->drawerFilterSql->apply($drawerFilter);
            if ([] !== $drawerConditions) {
                $where .= ' AND '.implode(' AND ', $drawerConditions);
                $params = [...$params, ...$drawerParams];
            }
        } elseif (true === $departmentWasClosed) {
            $where .= ' AND '.DepartmentWasClosedSql::closed();
        } elseif (false === $departmentWasClosed) {
            $where .= ' AND '.DepartmentWasClosedSql::regular();
        }

        $bandCase = IsochroneOriginBandSql::CASE_EXPRESSION;
        $sql = <<<SQL
SELECT {$bandCase} AS band_key, COUNT(*)::int AS count
FROM allocation_stats_projection
WHERE {$where}
GROUP BY 1
SQL;

        /** @var list<array{band_key: string, count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['band_key']] = (int) $row['count'];
        }

        return new IsochroneOriginBandQueryResult($counts);
    }
}
