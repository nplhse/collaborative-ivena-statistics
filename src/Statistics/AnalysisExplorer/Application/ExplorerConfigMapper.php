<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\User\Domain\Entity\User;

final readonly class ExplorerConfigMapper
{
    public function __construct(
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
    ) {
    }

    public function toFormData(AnalysisViewConfig $config): ExplorerEditFormData
    {
        return new ExplorerEditFormData(
            scopePeriod: $this->sideFromFilter($config->statisticsFilter),
            dimensionGrain: $config->dimensionGrain->value,
            chartType: $config->presentation->chartType->value,
        );
    }

    public function toViewConfig(ExplorerEditFormData $formData, AnalysisViewConfig $base, ?User $user): AnalysisViewConfig
    {
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
            $user,
        );

        $dimensionGrain = AnalysisDimensionGrain::tryFrom($formData->dimensionGrain) ?? AnalysisDimensionGrain::Month;
        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar;

        return $base
            ->withStatisticsFilter($filter)
            ->withDimensionGrain($dimensionGrain)
            ->withPresentation(new PresentationConfig(chartType: $chartType));
    }

    /**
     * @return array<string, mixed>
     */
    public function filterToStateArray(StatisticsFilter $filter): array
    {
        $side = $this->sideFromFilter($filter);

        return [
            'scopeGroup' => $side->scopeGroup,
            'scopeDetail' => $side->scopeDetail,
            'period' => $side->period,
            'periodYear' => $side->periodYear,
            'periodQuarter' => $side->periodQuarter,
            'periodMonth' => $side->periodMonth,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function viewPreferencesToStateArray(AnalysisViewConfig $config): array
    {
        return [
            'title' => $config->title,
            'dimensionGrain' => $config->dimensionGrain->value,
            'chartType' => $config->presentation->chartType->value,
        ];
    }

    /**
     * @param array<string, mixed> $filterState
     * @param array<string, mixed> $viewPreferences
     */
    public function buildViewConfig(array $filterState, array $viewPreferences, ?User $user): AnalysisViewConfig
    {
        $state = array_merge($filterState, $viewPreferences);

        return $this->viewConfigFromState($state, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStateArray(AnalysisViewConfig $config): array
    {
        return array_merge(
            $this->filterToStateArray($config->statisticsFilter),
            $this->viewPreferencesToStateArray($config),
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    public function viewConfigFromState(array $state, ?User $user): AnalysisViewConfig
    {
        $formData = new ExplorerEditFormData(
            scopePeriod: new BenchmarkSelectionSideFormData(
                (string) ($state['scopeGroup'] ?? 'public'),
                isset($state['scopeDetail']) ? (string) $state['scopeDetail'] : null,
                (string) ($state['period'] ?? 'all'),
                isset($state['periodYear']) ? (int) $state['periodYear'] : null,
                isset($state['periodQuarter']) ? (int) $state['periodQuarter'] : null,
                isset($state['periodMonth']) ? (int) $state['periodMonth'] : null,
            ),
            dimensionGrain: (string) ($state['dimensionGrain'] ?? 'month'),
            chartType: (string) ($state['chartType'] ?? 'bar'),
        );

        $base = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionGrain: AnalysisDimensionGrain::Month,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: (string) ($state['title'] ?? 'Allocations over time'),
        );

        return $this->toViewConfig($formData, $base, $user);
    }

    private function sideFromFilter(StatisticsFilter $filter): BenchmarkSelectionSideFormData
    {
        [$scopeGroup, $scopeDetail] = $this->scopeGroupFromFilter($filter);
        $now = new \DateTimeImmutable();

        return new BenchmarkSelectionSideFormData(
            $scopeGroup,
            $scopeDetail,
            $filter->period->value,
            $filter->referenceYear ?? (int) $now->format('Y'),
            $filter->referenceQuarter ?? (int) ceil((int) $now->format('n') / 3),
            $filter->referenceMonth ?? (int) $now->format('n'),
        );
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function scopeGroupFromFilter(StatisticsFilter $filter): array
    {
        return match ($filter->scope) {
            StatisticsFilterScope::Public => ['public', null],
            StatisticsFilterScope::State => ['state', null !== $filter->stateId ? (string) $filter->stateId : null],
            StatisticsFilterScope::DispatchArea => ['dispatch_area', null !== $filter->dispatchAreaId ? (string) $filter->dispatchAreaId : null],
            StatisticsFilterScope::HospitalCohort => [
                'hospital_cohort',
                $filter->cohortType?->value(),
            ],
            StatisticsFilterScope::MyHospitals => ['my_hospitals', null],
            StatisticsFilterScope::Hospital => ['my_hospitals', null !== $filter->hospitalId ? (string) $filter->hospitalId : null],
        };
    }
}
