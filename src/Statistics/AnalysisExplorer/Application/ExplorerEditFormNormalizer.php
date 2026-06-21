<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;

final readonly class ExplorerEditFormNormalizer
{
    public function __construct(
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
    ) {
    }

    public function normalize(ExplorerEditFormData $formData): ExplorerEditFormData
    {
        $capabilities = $this->capabilitiesProvider->capabilities();

        $dimension = AnalysisDimensionKey::tryFrom($formData->dimension) ?? $capabilities->defaultDimension;
        if (!\in_array($dimension, $capabilities->dimensions, true)) {
            $dimension = $capabilities->defaultDimension;
        }

        $metric = AnalysisMetricKey::tryFrom($formData->metric) ?? $capabilities->defaultMetric;
        if (!\in_array($metric, $capabilities->metrics, true)) {
            $metric = $capabilities->defaultMetric;
        }

        $timeGrain = $this->resolveTimeGrain($dimension, $formData->timeGrain, $capabilities);

        $previewConfig = $this->previewConfig($capabilities, $dimension, $metric, $timeGrain, $formData);
        $allowedCharts = $capabilities->chartTypesFor($previewConfig);
        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? $capabilities->defaultChartTypeFor($previewConfig);
        if (!\in_array($chartType, $allowedCharts, true)) {
            $chartType = $capabilities->defaultChartTypeFor($previewConfig);
        }

        return new ExplorerEditFormData(
            scopePeriod: $formData->scopePeriod,
            dimension: $dimension->value,
            metric: $metric->value,
            timeGrain: $timeGrain->value,
            chartType: $chartType->value,
        );
    }

    private function resolveTimeGrain(
        AnalysisDimensionKey $dimension,
        ?string $timeGrain,
        DataSourceCapabilities $capabilities,
    ): AnalysisDimensionGrain {
        $grain = null !== $timeGrain && '' !== $timeGrain
            ? AnalysisDimensionGrain::tryFrom($timeGrain)
            : null;

        if (AnalysisDimensionKey::Time !== $dimension && null === $grain) {
            $grain = AnalysisDimensionGrain::Total;
        }

        $allowed = $capabilities->timeGrainsFor($dimension);
        if ($grain instanceof AnalysisDimensionGrain && \in_array($grain, $allowed, true)) {
            return $grain;
        }

        return match ($dimension) {
            AnalysisDimensionKey::Time => $capabilities->defaultTimeGrain,
            AnalysisDimensionKey::Gender, AnalysisDimensionKey::Urgency => AnalysisDimensionGrain::Month,
        };
    }

    private function previewConfig(
        DataSourceCapabilities $capabilities,
        AnalysisDimensionKey $dimension,
        AnalysisMetricKey $metric,
        AnalysisDimensionGrain $timeGrain,
        ExplorerEditFormData $formData,
    ): AnalysisViewConfig {
        return new AnalysisViewConfig(
            dataSourceKey: $capabilities->dataSourceKey,
            metricKey: $metric,
            dimensionKey: $dimension,
            timeGrain: $timeGrain,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar,
            ),
            title: '',
        );
    }
}
