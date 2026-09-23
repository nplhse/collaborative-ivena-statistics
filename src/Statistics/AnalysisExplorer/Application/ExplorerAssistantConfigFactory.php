<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantReadiness;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class ExplorerAssistantConfigFactory
{
    public function __construct(
        private ExplorerTitleFactory $titleFactory,
        private ExplorerTableLayoutResolver $tableLayoutResolver,
        private ExplorerAnalysisFilterMapper $filterMapper,
    ) {
    }

    public function status(ExplorerAssistantQuery $query): ExplorerAssistantReadiness
    {
        if (!$query->goal instanceof ExplorerAssistantGoal) {
            return $query->malformed
                ? ExplorerAssistantReadiness::Unsupported
                : ExplorerAssistantReadiness::Incomplete;
        }

        if ($query->malformed) {
            return ExplorerAssistantReadiness::Unsupported;
        }

        $goalStatus = match ($query->goal) {
            ExplorerAssistantGoal::TimeSeries => $this->timeSeriesStatus($query),
            ExplorerAssistantGoal::Distribution,
            ExplorerAssistantGoal::Toplist => $this->characteristicStatus($query),
            ExplorerAssistantGoal::Matrix => $this->matrixStatus($query),
        };
        if (ExplorerAssistantReadiness::Ready !== $goalStatus) {
            return $goalStatus;
        }

        $metricChoices = AnalysisDataSourceKey::Hospitals === $query->dataSource
            ? AnalysisMetricKey::primaryHospitalMetricChoices()
            : AnalysisMetricKey::primaryMetricChoices();
        if (!$query->metric->isChartable() || !\in_array($query->metric, $metricChoices, true)) {
            return ExplorerAssistantReadiness::Unsupported;
        }

        return ExplorerAssistantReadiness::Ready;
    }

    public function create(ExplorerAssistantQuery $query, StatisticsFilter $filter): ?AnalysisViewConfig
    {
        if (ExplorerAssistantReadiness::Ready !== $this->status($query)) {
            return null;
        }

        $goal = $query->goal;
        if (!$goal instanceof ExplorerAssistantGoal) {
            return null;
        }

        $rowAxis = $this->rowAxis($goal, $query);
        $columnAxis = $this->columnAxis($query);
        $hasColumn = $columnAxis instanceof AnalysisAxisRef;

        $config = new AnalysisViewConfig(
            dataSourceKey: $query->dataSource,
            metricKeys: [$query->metric],
            visualMetricKey: $query->metric,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            statisticsFilter: $filter,
            presentation: new PresentationConfig(
                chartType: $this->chartType($goal, $query, $hasColumn),
                chartRowLimit: ExplorerAssistantGoal::Toplist === $goal && !$hasColumn
                    ? ExplorerChartRowLimit::Top10
                    : ExplorerChartRowLimit::All,
            ),
            title: $this->titleFactory->titleForAxes($rowAxis, $columnAxis, $query->dataSource),
            filters: $this->filtersFor($query),
        );

        return $config->withPresentation(
            $config->presentation->withTableLayout($this->tableLayoutResolver->resolveForConfig($config)),
        );
    }

    private function timeSeriesStatus(ExplorerAssistantQuery $query): ExplorerAssistantReadiness
    {
        $row = $query->row ?? AnalysisDimensionKey::Time;
        if ($row->isTemporalPrimary() && !\in_array($query->effectiveGrain(), ExplorerAssistantCatalog::timeGrains(), true)) {
            return ExplorerAssistantReadiness::Unsupported;
        }

        return $this->columnStatus($query) ?? ExplorerAssistantReadiness::Ready;
    }

    private function characteristicStatus(ExplorerAssistantQuery $query): ExplorerAssistantReadiness
    {
        if (!$query->row instanceof AnalysisDimensionKey) {
            return ExplorerAssistantReadiness::Incomplete;
        }

        return $this->columnStatus($query) ?? ExplorerAssistantReadiness::Ready;
    }

    private function matrixStatus(ExplorerAssistantQuery $query): ExplorerAssistantReadiness
    {
        if (!$query->row instanceof AnalysisDimensionKey || !$query->column instanceof AnalysisDimensionKey) {
            return ExplorerAssistantReadiness::Incomplete;
        }

        if ($query->row === $query->column) {
            return ExplorerAssistantReadiness::SameAxis;
        }

        return ExplorerAssistantReadiness::Ready;
    }

    private function columnStatus(ExplorerAssistantQuery $query): ?ExplorerAssistantReadiness
    {
        if (!$query->column instanceof AnalysisDimensionKey) {
            return null;
        }

        $row = $query->row ?? (ExplorerAssistantGoal::TimeSeries === $query->goal ? AnalysisDimensionKey::Time : null);
        if ($row === $query->column) {
            return ExplorerAssistantReadiness::SameAxis;
        }

        if ($query->column->isTemporalPrimary()
            && $query->columnGrain instanceof AnalysisDimensionGrain
            && !\in_array($query->columnGrain, ExplorerAssistantCatalog::timeGrains(), true)
            && AnalysisDimensionGrain::Total !== $query->columnGrain
        ) {
            return ExplorerAssistantReadiness::Unsupported;
        }

        return null;
    }

    private function rowAxis(ExplorerAssistantGoal $goal, ExplorerAssistantQuery $query): AnalysisAxisRef
    {
        $row = $query->row;
        if (!$row instanceof AnalysisDimensionKey) {
            $row = ExplorerAssistantGoal::TimeSeries === $goal
                ? AnalysisDimensionKey::Time
                : AnalysisDimensionKey::Urgency;
        }

        if ($row->isTemporalPrimary()) {
            return AnalysisAxisRef::time($query->effectiveGrain());
        }

        return AnalysisAxisRef::breakdown($row);
    }

    private function columnAxis(ExplorerAssistantQuery $query): ?AnalysisAxisRef
    {
        if (!$query->column instanceof AnalysisDimensionKey) {
            return null;
        }

        if ($query->column->isTemporalPrimary()) {
            return AnalysisAxisRef::time($query->columnGrain ?? $query->effectiveGrain());
        }

        return AnalysisAxisRef::breakdown($query->column);
    }

    private function chartType(ExplorerAssistantGoal $goal, ExplorerAssistantQuery $query, bool $hasColumn): ChartPresentationType
    {
        if ($query->metric->isDistributionProfile()) {
            return ChartPresentationType::BoxPlot;
        }

        if (AnalysisDataSourceKey::Hospitals === $query->dataSource) {
            return $hasColumn || ExplorerAssistantGoal::Matrix === $goal
                ? ChartPresentationType::Heatmap
                : ChartPresentationType::Bar;
        }

        return match ($goal) {
            ExplorerAssistantGoal::TimeSeries => ChartPresentationType::Line,
            ExplorerAssistantGoal::Toplist => $hasColumn ? ChartPresentationType::Heatmap : ChartPresentationType::Bar,
            ExplorerAssistantGoal::Distribution => $hasColumn ? ChartPresentationType::Heatmap : ChartPresentationType::Bar,
            ExplorerAssistantGoal::Matrix => ChartPresentationType::Heatmap,
        };
    }

    /**
     * @return list<\App\Statistics\GenericAnalysis\Domain\DTO\AnalysisFilter>
     */
    private function filtersFor(ExplorerAssistantQuery $query): array
    {
        $formData = new ExplorerEditFormData();
        $formData->filterDepartmentId = $query->filters->departmentId;
        $formData->filterSpecialityId = $query->filters->specialityId;
        $formData->filterUrgency = $query->filters->urgency;
        $formData->filterTransportType = $query->filters->transportType;
        $formData->filterGender = $query->filters->gender;
        $formData->filterAgeGroup = $query->filters->ageGroup;
        $formData->filterResus = $query->filters->resus;
        $formData->filterCpr = $query->filters->cpr;
        $formData->filterVentilation = $query->filters->ventilation;
        $formData->filterAssignmentId = $query->filters->assignmentId;
        $formData->filterIndicationId = $query->filters->indicationId;
        $formData->filterSecondaryIndicationId = $query->filters->secondaryIndicationId;
        $formData->filterIndicationGroupId = $query->filters->indicationGroupId;

        return $this->filterMapper->fromFormData($formData);
    }
}
