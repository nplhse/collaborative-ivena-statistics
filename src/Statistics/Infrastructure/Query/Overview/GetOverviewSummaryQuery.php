<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Infrastructure\Query\Overview\Dto\OverviewSummaryResult;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use Doctrine\DBAL\Connection;

final readonly class GetOverviewSummaryQuery
{
    public function __construct(
        private Connection $connection,
        private ProjectionFeatureQuery $projectionFeatureQuery,
    ) {
    }

    public function __invoke(OverviewQueryCriteria $criteria): OverviewSummaryResult
    {
        if ($criteria->hasEmptyHospitalScope()) {
            return new OverviewSummaryResult(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
        }

        $hasExtended = $this->projectionFeatureQuery->hasExtendedClinicalFeatureColumns();
        $shockExpr = $hasExtended ? 'COUNT(*) FILTER (WHERE is_shock = true)' : '0';
        $pregnantExpr = $hasExtended ? 'COUNT(*) FILTER (WHERE is_pregnant = true)' : '0';
        $workAccidentExpr = $hasExtended ? 'COUNT(*) FILTER (WHERE is_work_accident = true)' : '0';

        [$where, $params] = OverviewProjectionSqlFilter::buildWhereClause($criteria);

        $sql = <<<SQL
SELECT
    COUNT(*)::int AS total,
    COUNT(*) FILTER (WHERE is_with_physician = true)::int AS with_physician,
    COUNT(*) FILTER (WHERE is_cpr = true)::int AS cpr,
    COUNT(*) FILTER (WHERE is_ventilated = true)::int AS ventilated,
    {$shockExpr}::int AS shock,
    {$pregnantExpr}::int AS pregnant,
    {$workAccidentExpr}::int AS work_accident,
    COUNT(*) FILTER (WHERE infection_id IS NOT NULL)::int AS infectious,
    COUNT(*) FILTER (WHERE requires_cathlab = true)::int AS cathlab,
    COUNT(*) FILTER (WHERE requires_resus = true)::int AS resus
FROM allocation_stats_projection
WHERE {$where}
SQL;

        $fetched = $this->connection->fetchAssociative($sql, $params);
        /** @var array<string, int|string|null> $row */
        $row = false === $fetched ? [] : $fetched;

        return new OverviewSummaryResult(
            (int) ($row['total'] ?? 0),
            (int) ($row['with_physician'] ?? 0),
            (int) ($row['cpr'] ?? 0),
            (int) ($row['ventilated'] ?? 0),
            (int) ($row['shock'] ?? 0),
            (int) ($row['pregnant'] ?? 0),
            (int) ($row['work_accident'] ?? 0),
            (int) ($row['infectious'] ?? 0),
            (int) ($row['cathlab'] ?? 0),
            (int) ($row['resus'] ?? 0),
        );
    }
}
