<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\AccessTrend;
use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Infrastructure\Query\AnalyticsAccessTrendQuery;

/**
 * Long-term counted accesses for the admin usage analytics (#950).
 */
final readonly class AccessTrendService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsAccessTrendQuery $query,
        private AccessTrendBuilder $builder,
    ) {
    }

    public function getTrend(): AccessTrend
    {
        return $this->builder->build($this->query->dailyAccesses(), AnalyticsCalendar::startOfToday());
    }
}
