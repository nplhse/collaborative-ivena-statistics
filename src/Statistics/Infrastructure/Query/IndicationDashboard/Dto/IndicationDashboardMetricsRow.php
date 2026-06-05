<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Query\IndicationDashboard\Dto;

/**
 * Aggregated rates/counts for indication vs. baseline (other indications) in one row.
 */
final readonly class IndicationDashboardMetricsRow
{
    public function __construct(
        public int $totalIndication,
        public int $totalBaseline,
        public int $withPhysicianIndication,
        public int $withPhysicianBaseline,
        public int $resusIndication,
        public int $resusBaseline,
        public int $cathlabIndication,
        public int $cathlabBaseline,
        public int $urgencyEmergencyIndication,
        public int $urgencyEmergencyBaseline,
        public int $infectiousIndication,
        public int $infectiousBaseline,
        public int $cprIndication,
        public int $cprBaseline,
        public int $ventilatedIndication,
        public int $ventilatedBaseline,
        public int $shockIndication,
        public int $shockBaseline,
        public int $pregnantIndication,
        public int $pregnantBaseline,
        public int $workAccidentIndication,
        public int $workAccidentBaseline,
        public int $nightDaytimeIndication,
        public int $nightDaytimeBaseline,
        public int $weekendIndication,
        public int $weekendBaseline,
        public int $age80PlusIndication,
        public int $age80PlusBaseline,
        public int $maleIndication,
        public int $maleBaseline,
        public int $femaleIndication,
        public int $femaleBaseline,
        public ?float $medianAgeIndication,
        public ?float $medianAgeBaseline,
        public ?float $medianTransportMinutesIndication,
        public ?float $medianTransportMinutesBaseline,
        public int $groundTransportIndication,
        public int $groundTransportBaseline,
        public int $airTransportIndication,
        public int $airTransportBaseline,
        public int $urgencyInpatientIndication,
        public int $urgencyInpatientBaseline,
        public int $urgencyOutpatientIndication,
        public int $urgencyOutpatientBaseline,
    ) {
    }

    public function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }
}
