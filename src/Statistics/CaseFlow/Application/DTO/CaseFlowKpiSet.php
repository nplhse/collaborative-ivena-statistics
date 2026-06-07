<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowKpiSet
{
    public function __construct(
        public int $totalCases,
        public ?float $regionalSharePercent,
        public ?float $centralizationPercent,
        public ?float $meanTransportMinutes,
        public ?string $dominantOriginName,
        public ?float $dominantOriginSharePercent,
        public ?float $overregionalSharePercent,
        public ?float $emergencySharePercent,
    ) {
    }
}
