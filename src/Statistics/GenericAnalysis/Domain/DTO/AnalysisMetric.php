<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisMetricType;

final readonly class AnalysisMetric
{
    public function __construct(
        public AnalysisMetricType $type = AnalysisMetricType::Count,
    ) {
    }
}
