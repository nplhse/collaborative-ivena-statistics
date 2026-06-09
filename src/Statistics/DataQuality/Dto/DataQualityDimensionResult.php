<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

use App\Statistics\DataQuality\DataQualityLevel;

final readonly class DataQualityDimensionResult
{
    public function __construct(
        public string $key,
        public DataQualityLevel $level,
        public string $metricLabelKey,
        public string $metricValue,
        public string $hintKey,
        /** @var array<string, int|float|string> */
        public array $hintParameters = [],
    ) {
    }
}
