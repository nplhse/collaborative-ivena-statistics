<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\Overview;

use App\Statistics\Infrastructure\Query\Overview\GetOverviewGenderDistributionQuery;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewScopedTotalsQuery;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewSummaryQuery;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewUrgencyDistributionQuery;
use App\Statistics\Infrastructure\Query\ProjectionFeatureQuery;
use Doctrine\DBAL\Connection;

/**
 * Legacy overview queries are no longer wired in the application container; tests construct them directly.
 */
final readonly class OverviewLegacyQueryFactory
{
    public function __construct(
        private Connection $connection,
        private ProjectionFeatureQuery $projectionFeatureQuery,
    ) {
    }

    public function summary(): GetOverviewSummaryQuery
    {
        return new GetOverviewSummaryQuery($this->connection, $this->projectionFeatureQuery);
    }

    public function gender(): GetOverviewGenderDistributionQuery
    {
        return new GetOverviewGenderDistributionQuery($this->connection);
    }

    public function urgency(): GetOverviewUrgencyDistributionQuery
    {
        return new GetOverviewUrgencyDistributionQuery($this->connection);
    }

    public function scopedTotals(): GetOverviewScopedTotalsQuery
    {
        return new GetOverviewScopedTotalsQuery($this->connection);
    }
}
