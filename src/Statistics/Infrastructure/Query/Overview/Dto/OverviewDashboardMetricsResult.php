<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview\Dto;

final readonly class OverviewDashboardMetricsResult
{
    /**
     * @param array<string, int> $genderCounts
     * @param array<int, int>    $urgencyCounts
     * @param array<string, int> $ageGroupCounts
     */
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
        public array $genderCounts,
        public array $urgencyCounts,
        public int $nightDaytime,
        public int $weekend,
        public ?float $medianAge,
        public ?float $medianTransportMinutes,
        public array $ageGroupCounts,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, [], [], 0, 0, null, null, []);
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
