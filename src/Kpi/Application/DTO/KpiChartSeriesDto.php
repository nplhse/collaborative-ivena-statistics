<?php

declare(strict_types=1);

namespace App\Kpi\Application\DTO;

final readonly class KpiChartSeriesDto
{
    /**
     * @param list<string> $labels
     * @param list<int>    $recordsPerDay
     * @param list<float>  $rejectionRatePerDay
     */
    public function __construct(
        public array $labels,
        public array $recordsPerDay,
        public array $rejectionRatePerDay,
    ) {
    }

    /**
     * @return array{labels: list<string>, recordsPerDay: list<int>, rejectionRatePerDay: list<float>}
     */
    public function toChartValue(): array
    {
        return [
            'labels' => $this->labels,
            'recordsPerDay' => $this->recordsPerDay,
            'rejectionRatePerDay' => $this->rejectionRatePerDay,
        ];
    }
}
