<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use Doctrine\DBAL\Connection;

final readonly class CaseFlowBaselineQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{meanTransport: ?float, medianTransport: ?float, fullTierPercent: ?float}
     */
    public function fetchPublicBaseline(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $toExclusive,
    ): array {
        $publicScope = StatisticsScopeCriteria::public();
        [$where, $params, $types] = CaseFlowSqlFilter::buildScopePeriodWhere($from, $toExclusive, $publicScope);

        $sql = <<<SQL
SELECT
    AVG(asp.transport_time_minutes) AS mean_transport_minutes,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY asp.transport_time_minutes) AS median_transport_minutes,
    COUNT(*) FILTER (WHERE asp.hospital_tier_code = 3)::FLOAT / NULLIF(COUNT(*), 0) * 100 AS full_tier_percent
FROM allocation_stats_projection asp
WHERE {$where}
SQL;

        $row = $this->connection->fetchAssociative($sql, $params, $types);
        if (false === $row) {
            return ['meanTransport' => null, 'medianTransport' => null, 'fullTierPercent' => null];
        }

        return [
            'meanTransport' => null !== $row['mean_transport_minutes'] ? (float) $row['mean_transport_minutes'] : null,
            'medianTransport' => null !== $row['median_transport_minutes'] ? (float) $row['median_transport_minutes'] : null,
            'fullTierPercent' => null !== $row['full_tier_percent'] ? (float) $row['full_tier_percent'] : null,
        ];
    }
}
