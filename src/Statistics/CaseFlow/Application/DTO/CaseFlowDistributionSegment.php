<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowDistributionSegment
{
    public function __construct(
        public string $barClass,
        public string $labelTranslationKey,
        public int $count,
        public float $percent,
    ) {
    }
}
