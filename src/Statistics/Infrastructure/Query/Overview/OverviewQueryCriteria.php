<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\TimeSeries\TimeSeriesGrain;

final readonly class OverviewQueryCriteria
{
    /**
     * @param list<int>|null $hospitalIds null = no hospital filter; empty list = no matching rows
     */
    public function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $toExclusive,
        public ?array $hospitalIds,
        public ?int $dispatchAreaId = null,
        public TimeSeriesGrain $timeSeriesGrain = TimeSeriesGrain::Month,
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
    ): self {
        return new self($bounds->from, $bounds->toExclusive, $hospitalIds, $dispatchAreaId, $timeSeriesGrain);
    }

    public function hasEmptyHospitalScope(): bool
    {
        return \is_array($this->hospitalIds) && [] === $this->hospitalIds;
    }
}
