<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview\Dto;

final readonly class OverviewDashboardMetricsResult
{
    public function __construct(
        public int $platformTotal,
        public int $scopedTotal,
        public int $withPhysician,
        public int $cpr,
        public int $ventilated,
        public int $shock,
        public int $pregnant,
        public int $workAccident,
        public int $infectious,
        public int $cathlab,
        public int $resus,
        /** @var array<string, int> */
        public array $genderCounts,
        /** @var array<int, int> */
        public array $urgencyCounts,
    ) {
    }

    public function toSummaryResult(): OverviewSummaryResult
    {
        return new OverviewSummaryResult(
            $this->scopedTotal,
            $this->withPhysician,
            $this->cpr,
            $this->ventilated,
            $this->shock,
            $this->pregnant,
            $this->workAccident,
            $this->infectious,
            $this->cathlab,
            $this->resus,
        );
    }

    /**
     * @return array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,work_accident:int,infectious:int}
     */
    public function clinicalCounts(): array
    {
        return $this->toSummaryResult()->clinicalCounts();
    }

    /**
     * @return array{cathlab:int,resus:int}
     */
    public function resourceCounts(): array
    {
        return $this->toSummaryResult()->resourceCounts();
    }
}
