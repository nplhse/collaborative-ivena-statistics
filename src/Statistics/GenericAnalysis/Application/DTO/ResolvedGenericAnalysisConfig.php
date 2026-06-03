<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;

final readonly class ResolvedGenericAnalysisConfig
{
    public function __construct(
        public AnalysisQuery $query,
        public string $displayTitle,
        public bool $isCustom,
        public string $routePresetKey,
        public ?string $referencePresetKey,
        public string $primaryDimensionKey,
        public ?string $seriesDimensionKey,
        public bool $includeNullBuckets,
    ) {
    }
}
