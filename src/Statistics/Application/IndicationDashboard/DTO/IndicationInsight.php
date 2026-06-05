<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

final readonly class IndicationInsight
{
    public function __construct(
        public string $id,
        public IndicationInsightSeverity $severity,
        public string $translationKey,
        public float $ratio,
        public float $indicationPercent,
        public float $baselinePercent,
        public int $sortScore,
    ) {
    }
}
