<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationCompare\DTO;

final readonly class IndicationCompareInsight
{
    public function __construct(
        public string $id,
        public IndicationCompareInsightSeverity $severity,
        public string $translationKey,
        public float $ratio,
        public float $percentA,
        public float $percentB,
        public int $sortScore,
    ) {
    }
}
