<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Registry;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDataSourceDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisPeriodAppliesTo;

final class AnalysisDataSourceRegistry
{
    /** @var array<string, AnalysisDataSourceDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function get(AnalysisDataSource $source): AnalysisDataSourceDefinition
    {
        return $this->definitions[$source->value];
    }

    /**
     * @return list<AnalysisDataSourceDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    private function register(AnalysisDataSourceDefinition $definition): void
    {
        $this->definitions[$definition->source->value] = $definition;
    }

    private function registerDefaults(): void
    {
        $this->register(new AnalysisDataSourceDefinition(
            source: AnalysisDataSource::Allocations,
            labelTranslationKey: 'stats.generic_analysis.data_source.allocations',
            distributionBaseMetricKey: 'count',
            defaultPrimaryDimensionKey: 'month',
            periodAppliesTo: AnalysisPeriodAppliesTo::AllMetrics,
            supportsPopulationModifier: false,
        ));

        $this->register(new AnalysisDataSourceDefinition(
            source: AnalysisDataSource::Hospitals,
            labelTranslationKey: 'stats.generic_analysis.data_source.hospitals',
            distributionBaseMetricKey: 'hospital_count',
            defaultPrimaryDimensionKey: 'hospital_tier',
            periodAppliesTo: AnalysisPeriodAppliesTo::AllocationDerivedOnly,
            supportsPopulationModifier: true,
        ));
    }
}
