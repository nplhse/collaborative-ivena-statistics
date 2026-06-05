<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

final readonly class IndicationChartSeries
{
    /**
     * @param list<string> $labels
     * @param list<int>    $values
     */
    public function __construct(
        public array $labels,
        public array $values,
    ) {
    }
}
