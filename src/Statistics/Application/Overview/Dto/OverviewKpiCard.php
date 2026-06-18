<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

final readonly class OverviewKpiCard
{
    public function __construct(
        public string $key,
        public string $labelTranslationKey,
        public string $displayValue,
        public ?string $deltaDisplay,
        public ?string $deltaTone,
        public ?string $hintTranslationKey = null,
    ) {
    }
}
