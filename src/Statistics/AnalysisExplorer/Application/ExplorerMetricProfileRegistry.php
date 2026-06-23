<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\BoxPlotTableColumn;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDistributionValueSource;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;

final class ExplorerMetricProfileRegistry
{
    /** @var array<string, ExplorerMetricProfileDefinition> */
    private array $profiles;

    public function __construct()
    {
        $this->profiles = [
            AnalysisMetricKey::BedsDistribution->value => new ExplorerMetricProfileDefinition(
                storageKey: AnalysisMetricKey::BedsDistribution,
                labelTranslationKey: 'stats.analysis_explorer.metric_profile.beds_distribution',
                groupTranslationKey: 'stats.analysis_explorer.metric_group.beds',
                chartType: ChartPresentationType::BoxPlot,
                valueSource: ExplorerDistributionValueSource::HospitalBeds,
                tableColumns: BoxPlotTableColumn::cases(),
            ),
            AnalysisMetricKey::AllocationsPerHospitalDistribution->value => new ExplorerMetricProfileDefinition(
                storageKey: AnalysisMetricKey::AllocationsPerHospitalDistribution,
                labelTranslationKey: 'stats.analysis_explorer.metric_profile.allocations_per_hospital_distribution',
                groupTranslationKey: 'stats.analysis_explorer.metric_group.allocations',
                chartType: ChartPresentationType::BoxPlot,
                valueSource: ExplorerDistributionValueSource::AllocationsPerHospital,
                tableColumns: BoxPlotTableColumn::cases(),
            ),
        ];
    }

    public function isProfile(AnalysisMetricKey $metricKey): bool
    {
        return isset($this->profiles[$metricKey->value]);
    }

    public function profileFor(AnalysisMetricKey $metricKey): ?ExplorerMetricProfileDefinition
    {
        return $this->profiles[$metricKey->value] ?? null;
    }

    /**
     * @return list<AnalysisMetricKey>
     */
    public function profileMetricKeys(): array
    {
        return array_map(
            static fn (ExplorerMetricProfileDefinition $definition): AnalysisMetricKey => $definition->storageKey,
            array_values($this->profiles),
        );
    }

    /**
     * @return list<BoxPlotTableColumn>
     */
    public function tableColumnsFor(AnalysisMetricKey $metricKey): array
    {
        $profile = $this->profileFor($metricKey);
        if (!$profile instanceof ExplorerMetricProfileDefinition) {
            return [];
        }

        return $profile->tableColumns;
    }

    public function chartTypeFor(AnalysisMetricKey $metricKey): ?ChartPresentationType
    {
        return $this->profileFor($metricKey)?->chartType;
    }

    public function isAllowedForConfig(AnalysisViewConfig $config): bool
    {
        if (!$this->isProfile($config->visualMetricKey)) {
            return true;
        }

        if ($config->hasColumnAxis()) {
            return false;
        }

        if ($config->rowAxis->dimensionKey->isTemporalPrimary()) {
            return false;
        }

        return ExplorerHospitalPopulationMode::Compare !== $config->hospitalPopulationMode;
    }
}
