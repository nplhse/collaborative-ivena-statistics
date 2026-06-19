<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisPeriodAppliesTo;

final readonly class AnalysisDataSourceDefinition
{
    public function __construct(
        public AnalysisDataSource $source,
        public string $labelTranslationKey,
        public string $distributionBaseMetricKey,
        public string $defaultPrimaryDimensionKey,
        public AnalysisPeriodAppliesTo $periodAppliesTo = AnalysisPeriodAppliesTo::AllMetrics,
        public bool $supportsPopulationModifier = false,
    ) {
    }

    public function defaultMetricKey(): string
    {
        return $this->distributionBaseMetricKey;
    }
}
