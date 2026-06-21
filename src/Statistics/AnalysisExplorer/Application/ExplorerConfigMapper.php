<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\PresentationMode;
use App\Statistics\AnalysisExplorer\Domain\PresentationConfig;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\User\Domain\Entity\User;

final readonly class ExplorerConfigMapper
{
    private const int SCHEMA_VERSION = 1;

    public function __construct(
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
        private ExplorerTitleFactory $titleFactory,
        private AllocationsCapabilitiesProvider $capabilitiesProvider,
        private AnalysisDimensionGrainResolver $grainResolver,
    ) {
    }

    public function toFormData(AnalysisViewConfig $config): ExplorerEditFormData
    {
        return new ExplorerEditFormData(
            scopePeriod: $this->sideFromFilter($config->statisticsFilter),
            dimension: $config->dimensionKey->value,
            metric: $config->metricKey->value,
            timeGrain: $config->timeGrain?->value,
            chartType: $config->presentation->chartType->value,
        );
    }

    public function toViewConfig(ExplorerEditFormData $formData, AnalysisViewConfig $base, ?User $user): AnalysisViewConfig
    {
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
            $user,
        );

        $dimensionKey = AnalysisDimensionKey::tryFrom($formData->dimension) ?? AnalysisDimensionKey::Time;
        $metricKey = AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::AllocationCount;
        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar;
        $timeGrain = $this->grainResolver->resolveFromString(
            $dimensionKey,
            $formData->timeGrain,
            $this->capabilitiesProvider->capabilitiesFor($user, $filter),
        );

        return $base
            ->withStatisticsFilter($filter)
            ->withDimension($dimensionKey, $timeGrain)
            ->withMetric($metricKey)
            ->withPresentation(new PresentationConfig(chartType: $chartType, mode: PresentationMode::Chart))
            ->withTitle($this->titleFactory->titleFor($dimensionKey, $timeGrain));
    }

    /**
     * @return array<string, mixed>
     */
    public function toStateArray(AnalysisViewConfig $config): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'dataSource' => $config->dataSourceKey->value,
            'query' => [
                'scope' => $this->scopeToStateArray($config->statisticsFilter),
                'period' => $this->periodToStateArray($config->statisticsFilter),
                'metric' => $config->metricKey->value,
                'dimension' => $config->dimensionKey->value,
                'grain' => $config->timeGrain?->value,
            ],
            'presentation' => [
                'mode' => $config->presentation->mode->value,
                'chartType' => $config->presentation->chartType->value,
            ],
            'title' => $config->title,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function viewConfigFromState(array $state, ?User $user): AnalysisViewConfig
    {
        if ($this->isLegacyFlatState($state)) {
            $state = $this->upgradeLegacyState($state);
        }

        $scopePeriod = $this->scopePeriodFromState($state);
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($scopePeriod),
            $user,
        );
        $dimensionKey = AnalysisDimensionKey::tryFrom((string) ($state['query']['dimension'] ?? 'time')) ?? AnalysisDimensionKey::Time;
        $grainValue = $state['query']['grain'] ?? null;
        $timeGrain = $this->grainResolver->resolveFromString(
            $dimensionKey,
            \is_string($grainValue) ? $grainValue : null,
            $this->capabilitiesProvider->capabilitiesFor($user, $filter),
        );

        $formData = new ExplorerEditFormData(
            scopePeriod: $scopePeriod,
            dimension: $dimensionKey->value,
            metric: (string) ($state['query']['metric'] ?? AnalysisMetricKey::AllocationCount->value),
            timeGrain: $timeGrain->value,
            chartType: (string) ($state['presentation']['chartType'] ?? ChartPresentationType::Bar->value),
        );

        $base = new AnalysisViewConfig(
            dataSourceKey: AnalysisDataSourceKey::tryFrom((string) ($state['dataSource'] ?? 'allocations')) ?? AnalysisDataSourceKey::Allocations,
            metricKey: AnalysisMetricKey::AllocationCount,
            dimensionKey: AnalysisDimensionKey::Time,
            timeGrain: AnalysisDimensionGrain::Month,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(chartType: ChartPresentationType::Bar),
            title: (string) ($state['title'] ?? $this->titleFactory->titleFor(AnalysisDimensionKey::Time)),
        );

        return $this->toViewConfig($formData, $base, $user);
    }

    /**
     * @param array<string, mixed> $filterState
     * @param array<string, mixed> $viewPreferences
     */
    public function buildViewConfig(array $filterState, array $viewPreferences, ?User $user): AnalysisViewConfig
    {
        $state = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'dataSource' => AnalysisDataSourceKey::Allocations->value,
            'query' => array_merge($filterState, [
                'metric' => $viewPreferences['metric'] ?? AnalysisMetricKey::AllocationCount->value,
                'dimension' => $viewPreferences['dimension'] ?? AnalysisDimensionKey::Time->value,
                'grain' => $viewPreferences['grain'] ?? AnalysisDimensionGrain::Month->value,
            ]),
            'presentation' => [
                'mode' => PresentationMode::Chart->value,
                'chartType' => $viewPreferences['chartType'] ?? ChartPresentationType::Bar->value,
            ],
            'title' => $viewPreferences['title'] ?? '',
        ];

        return $this->viewConfigFromState($state, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function filterToStateArray(StatisticsFilter $filter): array
    {
        return [
            'scope' => $this->scopeToStateArray($filter),
            'period' => $this->periodToStateArray($filter),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function viewPreferencesToStateArray(AnalysisViewConfig $config): array
    {
        return [
            'metric' => $config->metricKey->value,
            'dimension' => $config->dimensionKey->value,
            'grain' => $config->timeGrain?->value,
            'chartType' => $config->presentation->chartType->value,
            'title' => $config->title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeToStateArray(StatisticsFilter $filter): array
    {
        [$group, $detail] = $this->scopeGroupFromFilter($filter);

        return [
            'group' => $group,
            'detail' => $detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodToStateArray(StatisticsFilter $filter): array
    {
        return [
            'type' => $filter->period->value,
            'year' => $filter->referenceYear,
            'quarter' => $filter->referenceQuarter,
            'month' => $filter->referenceMonth,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function scopePeriodFromState(array $state): StatisticsScopePeriodFormData
    {
        if (isset($state['query']['scope'], $state['query']['period']) && \is_array($state['query']['scope']) && \is_array($state['query']['period'])) {
            $scope = $state['query']['scope'];
            $period = $state['query']['period'];

            return new StatisticsScopePeriodFormData(
                (string) ($scope['group'] ?? 'public'),
                isset($scope['detail']) ? (string) $scope['detail'] : null,
                (string) ($period['type'] ?? 'all'),
                isset($period['year']) ? (int) $period['year'] : null,
                isset($period['quarter']) ? (int) $period['quarter'] : null,
                isset($period['month']) ? (int) $period['month'] : null,
            );
        }

        return new StatisticsScopePeriodFormData(
            (string) ($state['scopeGroup'] ?? 'public'),
            isset($state['scopeDetail']) ? (string) $state['scopeDetail'] : null,
            (string) ($state['period'] ?? 'all'),
            isset($state['periodYear']) ? (int) $state['periodYear'] : null,
            isset($state['periodQuarter']) ? (int) $state['periodQuarter'] : null,
            isset($state['periodMonth']) ? (int) $state['periodMonth'] : null,
        );
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function upgradeLegacyState(array $state): array
    {
        $dimension = 'time';
        $grain = (string) ($state['dimensionGrain'] ?? 'month');

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'dataSource' => AnalysisDataSourceKey::Allocations->value,
            'query' => [
                'scope' => [
                    'group' => (string) ($state['scopeGroup'] ?? 'public'),
                    'detail' => $state['scopeDetail'] ?? null,
                ],
                'period' => [
                    'type' => (string) ($state['period'] ?? 'all'),
                    'year' => $state['periodYear'] ?? null,
                    'quarter' => $state['periodQuarter'] ?? null,
                    'month' => $state['periodMonth'] ?? null,
                ],
                'metric' => AnalysisMetricKey::AllocationCount->value,
                'dimension' => $dimension,
                'grain' => $grain,
            ],
            'presentation' => [
                'mode' => PresentationMode::Chart->value,
                'chartType' => (string) ($state['chartType'] ?? ChartPresentationType::Bar->value),
            ],
            'title' => (string) ($state['title'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isLegacyFlatState(array $state): bool
    {
        return !isset($state['schemaVersion']) && isset($state['scopeGroup']);
    }

    private function sideFromFilter(StatisticsFilter $filter): StatisticsScopePeriodFormData
    {
        [$scopeGroup, $scopeDetail] = $this->scopeGroupFromFilter($filter);
        $now = new \DateTimeImmutable();

        return new StatisticsScopePeriodFormData(
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
