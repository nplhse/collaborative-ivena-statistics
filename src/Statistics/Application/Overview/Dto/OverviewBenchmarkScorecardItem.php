<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview\Dto;

final readonly class OverviewBenchmarkScorecardItem
{
    public function __construct(
        public string $key,
        public string $labelTranslationKey,
        public string $displayValue,
        public string $status,
        public string $statusLabelTranslationKey,
    ) {
    }
}
