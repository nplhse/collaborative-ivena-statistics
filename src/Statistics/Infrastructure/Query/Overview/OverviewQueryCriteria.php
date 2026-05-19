<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;

final readonly class OverviewQueryCriteria
{
    /**
     * @param list<int>|null $hospitalIds null = no hospital filter; empty list = no matching rows
     */
    public function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $toExclusive,
        public ?array $hospitalIds,
    ) {
    }

    /**
     * @param list<int>|null $hospitalIds
     */
    public static function fromPeriodBounds(StatisticsPeriodBounds $bounds, ?array $hospitalIds): self
    {
        return new self($bounds->from, $bounds->toExclusive, $hospitalIds);
    }

    public function hasEmptyHospitalScope(): bool
    {
        return \is_array($this->hospitalIds) && [] === $this->hospitalIds;
    }
}
