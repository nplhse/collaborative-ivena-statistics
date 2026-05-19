<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\Overview\Dto;

final readonly class OverviewSummaryResult
{
    public function __construct(
        public int $total,
        public int $withPhysician,
        public int $cpr,
        public int $ventilated,
        public int $shock,
        public int $pregnant,
        public int $workAccident,
        public int $infectious,
        public int $cathlab,
        public int $resus,
    ) {
    }

    /**
     * @return array{with_physician:int,cpr:int,ventilated:int,shock:int,pregnant:int,work_accident:int,infectious:int}
     */
    public function clinicalCounts(): array
    {
        return [
            'with_physician' => $this->withPhysician,
            'cpr' => $this->cpr,
            'ventilated' => $this->ventilated,
            'shock' => $this->shock,
            'pregnant' => $this->pregnant,
            'work_accident' => $this->workAccident,
            'infectious' => $this->infectious,
        ];
    }

    /**
     * @return array{cathlab:int,resus:int}
     */
    public function resourceCounts(): array
    {
        return [
            'cathlab' => $this->cathlab,
            'resus' => $this->resus,
        ];
    }
}
