<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationDashboard\DTO;

final readonly class IndicationSummarySegment
{
    public function __construct(
        public string $barClass,
        public string $labelTranslationKey,
        public int $count,
        public float $percent,
    ) {
    }
}
