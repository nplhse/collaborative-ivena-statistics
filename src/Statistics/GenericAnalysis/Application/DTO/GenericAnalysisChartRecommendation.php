<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;

final readonly class GenericAnalysisChartRecommendation
{
    /**
     * @param list<GenericAnalysisChartType> $allowedChartTypes
     * @param list<string>                   $warnings
     */
    public function __construct(
        public bool $hasChart,
        public GenericAnalysisChartType $defaultChartType,
        public array $allowedChartTypes,
        public array $warnings = [],
        public ?string $reason = null,
    ) {
    }
}
