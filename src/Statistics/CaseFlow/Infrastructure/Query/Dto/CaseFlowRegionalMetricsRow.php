<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Infrastructure\Query\Dto;

final readonly class CaseFlowRegionalMetricsRow
{
    public function __construct(
        public int $totalCases,
        public int $regionalCases,
        public int $fullTierCases,
        public int $emergencyCases,
        public ?float $meanTransportMinutes,
        public ?float $medianTransportMinutes,
    ) {
    }
}
