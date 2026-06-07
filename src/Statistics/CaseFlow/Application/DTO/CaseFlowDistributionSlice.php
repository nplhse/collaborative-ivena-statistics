<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application\DTO;

final readonly class CaseFlowDistributionSlice
{
    public function __construct(
        public string $key,
        public string $labelTranslationKey,
        public int $count,
        public float $percent,
    ) {
    }
}
