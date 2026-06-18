<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

final readonly class OverviewDistributionSegment
{
    public function __construct(
        public string $barClass,
        public string $labelTranslationKey,
        public int $count,
        public float $percent,
    ) {
    }
}
