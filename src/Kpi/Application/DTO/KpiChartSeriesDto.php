<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

final readonly class KpiChartSeriesDto
{
    /**
     * @param list<string>     $labels
     * @param list<int>        $importedPerDay      rows read minus rows rejected
     * @param list<int>        $rejectedPerDay
     * @param list<float|null> $rejectionRatePerDay null on days without rows read
     */
    public function __construct(
        public array $labels,
        public array $importedPerDay,
        public array $rejectedPerDay,
        public array $rejectionRatePerDay,
    ) {
    }

    /**
     * @return array{labels: list<string>, importedPerDay: list<int>, rejectedPerDay: list<int>, rejectionRatePerDay: list<float|null>}
     */
    public function toChartValue(): array
    {
        return [
            'labels' => $this->labels,
            'importedPerDay' => $this->importedPerDay,
            'rejectedPerDay' => $this->rejectedPerDay,
            'rejectionRatePerDay' => $this->rejectionRatePerDay,
        ];
    }
}
