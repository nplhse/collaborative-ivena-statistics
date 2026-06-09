<?php

declare(strict_types=1);

namespace App\Statistics\DataQuality\Dto;

use App\Statistics\DataQuality\DataQualityLevel;

final readonly class DataQualityReport
{
    /**
     * @param list<DataQualityDimensionResult> $dimensions
     */
    public function __construct(
        public DataQualityLevel $overallLevel,
        public string $explanationKey,
        /** @var array<string, int|float|string> */
        public array $explanationParameters,
        public string $scopeLabel,
        public string $periodLabel,
        public CoverageResult $coverage,
        public RepresentativenessResult $representativeness,
        public SubgroupSupportResult $subgroupSupport,
        public AllocationVolumeResult $allocationVolume,
        public array $dimensions,
    ) {
    }
}
