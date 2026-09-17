<?php

declare(strict_types=1);

namespace App\Statistics\Application\InsightCompare\DTO;

final readonly class InsightCompareInsight
{
    public function __construct(
        public string $id,
        public InsightCompareInsightSeverity $severity,
        public string $translationKey,
        public float $ratio,
        public float $percentA,
        public float $percentB,
        public int $sortScore,
    ) {
    }
}
