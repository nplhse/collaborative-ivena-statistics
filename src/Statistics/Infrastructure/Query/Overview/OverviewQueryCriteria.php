<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\DTO\StatisticsDrawerFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;

final readonly class OverviewQueryCriteria
{
    /**
     * @param list<int>|null              $hospitalIds  null = no hospital filter; empty list = no matching rows
     * @param StatisticsDrawerFilter|null $drawerFilter filter drawer of the page; null = no drawer filter (#1039)
     */
    public function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $toExclusive,
        public ?array $hospitalIds,
        public ?int $dispatchAreaId = null,
        public TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
        public ?StatisticsDrawerFilter $drawerFilter = null,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    public static function fromPeriodBounds(
        StatisticsPeriodBounds $bounds,
        ?array $hospitalIds,
        ?int $dispatchAreaId = null,
        TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
        ?StatisticsDrawerFilter $drawerFilter = null,
    ): self {
        return new self($bounds->from, $bounds->toExclusive, $hospitalIds, $dispatchAreaId, $timeSeriesGrain, $drawerFilter);
    }

    public function hasEmptyHospitalScope(): bool
    {
        return \is_array($this->hospitalIds) && [] === $this->hospitalIds;
    }
}
