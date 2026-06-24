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
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerHospitalPopulationMode;
use App\Statistics\AnalysisExplorer\Domain\Enum\PresentationMode;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;
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
    private const int SCHEMA_VERSION = 3;

    public function __construct(
        private ExplorerStatisticsFilterInputFactory $filterInputFactory,
        private StatisticsFilterFactory $statisticsFilterFactory,
        private ExplorerTitleFactory $titleFactory,
        private DataSourceCapabilitiesRegistry $capabilitiesRegistry,
        private AnalysisAxisResolver $axisResolver,
        private AnalysisAxisUpgradeMapper $axisUpgradeMapper,
        private ExplorerConfigPreviewFactory $previewFactory,
        private ExplorerMetricCapabilityPolicy $metricCapabilityPolicy,
        private ExplorerTableLayoutResolver $tableLayoutResolver,
    ) {
    }

    public function toFormData(AnalysisViewConfig $config): ExplorerEditFormData
    {
        return new ExplorerEditFormData(
            scopePeriod: $this->sideFromFilter($config->statisticsFilter),
            dataSource: $config->dataSourceKey->value,
            rowDimension: $config->rowAxis->dimensionKey->value,
            rowGrain: $config->rowAxis->resolvedGrain()->value,
            columnDimension: $config->columnAxis?->dimensionKey->value,
            columnGrain: $config->columnAxis?->resolvedGrain()->value,
            metric: $config->visualMetricKey->value,
            showPercentOfTotal: $config->showsPercentOfTotal(),
            chartType: $config->presentation->chartType->value,
            tableLayout: $config->presentation->tableLayout->value,
            chartRowLimit: $config->presentation->chartRowLimit->value,
            hospitalPopulation: $config->hospitalPopulationMode->value,
            additionalTableMetrics: AnalysisMetricKey::additionalTableMetricValues(
                $config->metricKeys,
                $config->visualMetricKey,
            ),
        );
    }

    public function toViewConfig(ExplorerEditFormData $formData, AnalysisViewConfig $base, ?User $user): AnalysisViewConfig
    {
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($formData->scopePeriod),
            $user,
        );

        $visualMetricKey = AnalysisMetricKey::tryFrom($formData->metric) ?? AnalysisMetricKey::defaultFor($base->dataSourceKey);
        $chartType = ChartPresentationType::tryFrom($formData->chartType) ?? ChartPresentationType::Bar;
        $tableLayout = TableLayout::tryFrom($formData->tableLayout) ?? TableLayout::Flat;
        $capabilities = $this->capabilitiesRegistry->capabilitiesFor($base->dataSourceKey, $user, $filter);

        [$rowAxis, $columnAxis] = $this->axesFromFormData($formData, $capabilities);

        $preview = $this->previewFactory->fromFormData(
            $capabilities,
            $rowAxis,
            $columnAxis,
            $visualMetricKey,
            $formData,
        );

        $metricKeys = $this->metricCapabilityPolicy->normalizeMetricKeys($preview->metricKeys, $preview->withStatisticsFilter($filter));
        if (!\in_array($visualMetricKey, $metricKeys, true)) {
            $visualMetricKey = $metricKeys[0];
        }

        if (TableLayout::Flat === $tableLayout && null !== $columnAxis) {
            $tableLayout = $this->tableLayoutResolver->resolveForConfig($preview);
        }

        $chartRowLimit = ExplorerChartRowLimit::fromValue($formData->chartRowLimit);
        if ($rowAxis->dimensionKey->isTemporalPrimary()) {
            $chartRowLimit = ExplorerChartRowLimit::All;
        }

        $hospitalPopulationMode = ExplorerHospitalPopulationMode::tryFrom($formData->hospitalPopulation)
            ?? $base->hospitalPopulationMode;

        return $base
            ->withStatisticsFilter($filter)
            ->withAxes($rowAxis, $columnAxis)
            ->withMetrics($metricKeys, $visualMetricKey)
            ->withPresentation(new PresentationConfig(
                chartType: $chartType,
                mode: PresentationMode::Chart,
                tableLayout: $tableLayout,
                chartRowLimit: $chartRowLimit,
            ))
            ->withTitle($this->titleFactory->titleForAxes($rowAxis, $columnAxis))
            ->withHospitalPopulationMode($hospitalPopulationMode);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStateArray(AnalysisViewConfig $config): array
    {
        $query = [
            'scope' => $this->scopeToStateArray($config->statisticsFilter),
            'period' => $this->periodToStateArray($config->statisticsFilter),
            'hospitalPopulation' => $config->hospitalPopulationMode->value,
            'metrics' => array_map(static fn (AnalysisMetricKey $key): string => $key->value, $config->metricKeys),
            'visualMetric' => $config->visualMetricKey->value,
            'rows' => $config->rowAxis->toStateArray(),
            'columns' => $config->columnAxis?->toStateArray(),
        ];

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'dataSource' => $config->dataSourceKey->value,
            'query' => $query,
            'presentation' => [
                'mode' => $config->presentation->mode->value,
                'chartType' => $config->presentation->chartType->value,
                'tableLayout' => $config->presentation->tableLayout->value,
                'chartRowLimit' => $config->presentation->chartRowLimit->value,
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

        $state = $this->upgradeMetricState($state);
        $state = $this->upgradeAxisState($state);

        $dataSourceKey = AnalysisDataSourceKey::tryFrom((string) ($state['dataSource'] ?? 'allocations')) ?? AnalysisDataSourceKey::Allocations;

        $scopePeriod = $this->scopePeriodFromState($state);
        $filter = $this->statisticsFilterFactory->createFromInput(
            $this->filterInputFactory->fromSideFormData($scopePeriod),
            $user,
        );
        $capabilities = $this->capabilitiesRegistry->capabilitiesFor($dataSourceKey, $user, $filter);

        [$metricKeys, $visualMetricKey] = $this->metricKeysFromState($state['query'] ?? []);
        [$rowAxis, $columnAxis] = $this->axesFromState($state['query'] ?? [], $capabilities);

        $queryState = \is_array($state['query'] ?? null) ? $state['query'] : [];
        $chartRowLimit = ExplorerChartRowLimit::fromValue((string) ($state['presentation']['chartRowLimit'] ?? ExplorerChartRowLimit::All->value));

        $formData = new ExplorerEditFormData(
            scopePeriod: $scopePeriod,
            dataSource: $dataSourceKey->value,
            rowDimension: $rowAxis->dimensionKey->value,
            rowGrain: $rowAxis->resolvedGrain()->value,
            columnDimension: $columnAxis?->dimensionKey->value,
            columnGrain: $columnAxis?->resolvedGrain()->value,
            metric: $visualMetricKey->value,
            showPercentOfTotal: \in_array(AnalysisMetricKey::PercentOfTotal, $metricKeys, true),
            chartType: (string) ($state['presentation']['chartType'] ?? ChartPresentationType::Bar->value),
            tableLayout: (string) ($state['presentation']['tableLayout'] ?? TableLayout::Flat->value),
            chartRowLimit: $chartRowLimit->value,
            hospitalPopulation: (string) ($queryState['hospitalPopulation'] ?? ExplorerHospitalPopulationMode::Participating->value),
            additionalTableMetrics: AnalysisMetricKey::additionalTableMetricValues($metricKeys, $visualMetricKey),
        );

        $base = new AnalysisViewConfig(
            dataSourceKey: $dataSourceKey,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
            columnAxis: null,
            statisticsFilter: new StatisticsFilter(
                scope: StatisticsFilterScope::Public,
                hospitalId: null,
                cohortType: null,
                period: StatisticsFilterPeriod::All,
            ),
            presentation: new PresentationConfig(
                chartType: ChartPresentationType::Bar,
                chartRowLimit: $chartRowLimit,
            ),
            title: (string) ($state['title'] ?? $this->titleFactory->titleForAxes(AnalysisAxisRef::time(AnalysisDimensionGrain::Month), null)),
        );

        $config = $this->toViewConfig($formData, $base, $user);

        if ('' !== trim((string) ($state['title'] ?? ''))) {
            $config = $config->withTitle((string) $state['title']);
        }

        return $config->withPresentation($config->presentation->withChartRowLimit($chartRowLimit));
    }

    /**
     * @param array<string, mixed> $filterState
     * @param array<string, mixed> $viewPreferences
     */
    public function buildViewConfig(array $filterState, array $viewPreferences, ?User $user): AnalysisViewConfig
    {
        $dataSourceKey = AnalysisDataSourceKey::tryFrom((string) ($viewPreferences['dataSource'] ?? AnalysisDataSourceKey::Allocations->value))
            ?? AnalysisDataSourceKey::Allocations;
        [$metrics, $visualMetric] = $this->resolveMetricsFromPreferences($viewPreferences, $dataSourceKey);

        $query = array_merge($filterState, [
            'metrics' => $metrics,
            'visualMetric' => $visualMetric,
        ]);

        if (isset($viewPreferences['rows']) && \is_array($viewPreferences['rows'])) {
            $query['rows'] = $viewPreferences['rows'];
            $query['columns'] = $viewPreferences['columns'] ?? null;
        } elseif (isset($viewPreferences['dimension'])) {
            $dimensionKey = AnalysisDimensionKey::tryFrom((string) $viewPreferences['dimension']) ?? AnalysisDimensionKey::Time;
            $grain = $this->resolveLegacyGrain($dimensionKey, $viewPreferences['grain'] ?? null);
            [$rowAxis, $columnAxis] = $this->axisUpgradeMapper->fromLegacyDimension($dimensionKey, $grain);
            $query['rows'] = $rowAxis->toStateArray();
            $query['columns'] = $columnAxis?->toStateArray();
        } else {
            $query['rows'] = [
                'dimension' => AnalysisDimensionKey::Time->value,
                'grain' => AnalysisDimensionGrain::Month->value,
            ];
            $query['columns'] = null;
        }

        $state = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'dataSource' => (string) ($viewPreferences['dataSource'] ?? AnalysisDataSourceKey::Allocations->value),
            'query' => array_merge($query, [
                'hospitalPopulation' => (string) ($viewPreferences['hospitalPopulation'] ?? ExplorerHospitalPopulationMode::Participating->value),
            ]),
            'presentation' => [
                'mode' => PresentationMode::Chart->value,
                'chartType' => $viewPreferences['chartType'] ?? ChartPresentationType::Bar->value,
                'tableLayout' => $viewPreferences['tableLayout'] ?? TableLayout::Flat->value,
                'chartRowLimit' => $viewPreferences['chartRowLimit'] ?? ExplorerChartRowLimit::All->value,
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
            'metrics' => array_map(static fn (AnalysisMetricKey $key): string => $key->value, $config->metricKeys),
            'visualMetric' => $config->visualMetricKey->value,
            'rows' => $config->rowAxis->toStateArray(),
            'columns' => $config->columnAxis?->toStateArray(),
            'chartType' => $config->presentation->chartType->value,
            'tableLayout' => $config->presentation->tableLayout->value,
            'chartRowLimit' => $config->presentation->chartRowLimit->value,
            'title' => $config->title,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function mergeChartRowLimitIntoState(array $state, ExplorerChartRowLimit $chartRowLimit): array
    {
        $presentation = $state['presentation'] ?? [];
        if (!\is_array($presentation)) {
            $presentation = [];
        }

        return array_merge($state, [
            'presentation' => array_merge($presentation, [
                'chartRowLimit' => $chartRowLimit->value,
            ]),
        ]);
    }

    /**
     * @return array{0: AnalysisAxisRef, 1: ?AnalysisAxisRef}
     */
    private function axesFromFormData(ExplorerEditFormData $formData, \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities): array
    {
        $rowAxis = $this->axisResolver->resolveFromStrings(
            $formData->rowDimension,
            $formData->rowGrain,
            $capabilities,
        );

        $columnAxis = null;
        if (null !== $formData->columnDimension && '' !== $formData->columnDimension) {
            $columnAxis = $this->axisResolver->resolveFromStrings(
                $formData->columnDimension,
                $formData->columnGrain,
                $capabilities,
            );
        }

        return [$rowAxis, $columnAxis];
    }

    /**
     * @param array<string, mixed> $queryState
     *
     * @return array{0: AnalysisAxisRef, 1: ?AnalysisAxisRef}
     */
    private function axesFromState(array $queryState, \App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities $capabilities): array
    {
        if (isset($queryState['rows']) && \is_array($queryState['rows'])) {
            $rowAxis = $this->axisResolver->resolve(AnalysisAxisRef::fromStateArray($queryState['rows']), $capabilities);
            $columnAxis = null;
            if (isset($queryState['columns']) && \is_array($queryState['columns'])) {
                $columnAxis = $this->axisResolver->resolve(AnalysisAxisRef::fromStateArray($queryState['columns']), $capabilities);
            }

            return [$rowAxis, $columnAxis];
        }

        $dimensionKey = AnalysisDimensionKey::tryFrom((string) ($queryState['dimension'] ?? 'time')) ?? AnalysisDimensionKey::Time;
        $grain = $this->resolveLegacyGrain($dimensionKey, $queryState['grain'] ?? null);
        [$rowAxis, $columnAxis] = $this->axisUpgradeMapper->fromLegacyDimension($dimensionKey, $grain);

        return [
            $this->axisResolver->resolve($rowAxis, $capabilities),
            null !== $columnAxis ? $this->axisResolver->resolve($columnAxis, $capabilities) : null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function upgradeAxisState(array $state): array
    {
        if (!isset($state['query']) || !\is_array($state['query'])) {
            return $state;
        }

        if (isset($state['query']['rows'])) {
            return $state;
        }

        if (!isset($state['query']['dimension'])) {
            return $state;
        }

        $dimensionKey = AnalysisDimensionKey::tryFrom((string) $state['query']['dimension']) ?? AnalysisDimensionKey::Time;
        $grain = $this->resolveLegacyGrain($dimensionKey, $state['query']['grain'] ?? null);
        [$rowAxis, $columnAxis] = $this->axisUpgradeMapper->fromLegacyDimension($dimensionKey, $grain);

        $state['query']['rows'] = $rowAxis->toStateArray();
        $state['query']['columns'] = $columnAxis?->toStateArray();

        return $state;
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
     * @return array{0: list<AnalysisMetricKey>, 1: AnalysisMetricKey}
     */
    /**
     * @param array<string, mixed> $viewPreferences
     *
     * @return array{0: list<string>, 1: string}
     */
    private function resolveMetricsFromPreferences(array $viewPreferences, AnalysisDataSourceKey $dataSourceKey): array
    {
        if (isset($viewPreferences['metrics']) && \is_array($viewPreferences['metrics'])) {
            $metrics = [];
            foreach ($viewPreferences['metrics'] as $metric) {
                if ('' !== (string) $metric) {
                    $metrics[] = (string) $metric;
                }
            }

            if ([] === $metrics) {
                $defaultMetric = AnalysisMetricKey::defaultFor($dataSourceKey)->value;

                return [[$defaultMetric], $defaultMetric];
            }

            $visualMetric = (string) ($viewPreferences['visualMetric']
                ?? ($viewPreferences['metric'] ?? $metrics[0]));

            return [$metrics, $visualMetric];
        }

        if (isset($viewPreferences['metric']) && '' !== (string) $viewPreferences['metric']) {
            $metric = (string) $viewPreferences['metric'];

            return [
                [$metric],
                (string) ($viewPreferences['visualMetric'] ?? $metric),
            ];
        }

        $defaultMetric = AnalysisMetricKey::defaultFor($dataSourceKey)->value;

        return [
            [$defaultMetric],
            (string) ($viewPreferences['visualMetric'] ?? $defaultMetric),
        ];
    }

    /**
     * @param array<string, mixed> $queryState
     *
     * @return array{0: list<AnalysisMetricKey>, 1: AnalysisMetricKey}
     */
    private function metricKeysFromState(array $queryState): array
    {
        if (isset($queryState['metrics']) && \is_array($queryState['metrics'])) {
            $metricKeys = [];
            foreach ($queryState['metrics'] as $metric) {
                $key = AnalysisMetricKey::tryFrom((string) $metric);
                if ($key instanceof AnalysisMetricKey) {
                    $metricKeys[] = $key;
                }
            }

            if ([] === $metricKeys) {
                $metricKeys = [AnalysisMetricKey::AllocationCount];
            }

            $visualMetric = AnalysisMetricKey::tryFrom((string) ($queryState['visualMetric'] ?? ''))
                ?? $metricKeys[0];

            if (!\in_array($visualMetric, $metricKeys, true)) {
                $visualMetric = $metricKeys[0];
            }

            return [$metricKeys, $visualMetric];
        }

        $legacyMetric = AnalysisMetricKey::tryFrom((string) ($queryState['metric'] ?? AnalysisMetricKey::AllocationCount->value))
            ?? AnalysisMetricKey::AllocationCount;

        return [[$legacyMetric], $legacyMetric];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function upgradeMetricState(array $state): array
    {
        if (!isset($state['query']) || !\is_array($state['query'])) {
            return $state;
        }

        if (isset($state['query']['metrics'])) {
            return $state;
        }

        $legacyMetric = (string) ($state['query']['metric'] ?? AnalysisMetricKey::AllocationCount->value);
        $state['query']['metrics'] = [$legacyMetric];
        $state['query']['visualMetric'] = $legacyMetric;

        return $state;
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
            'schemaVersion' => 1,
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

    private function resolveLegacyGrain(AnalysisDimensionKey $dimensionKey, mixed $grainValue): AnalysisDimensionGrain
    {
        if (\is_string($grainValue) && '' !== $grainValue) {
            return AnalysisDimensionGrain::tryFrom($grainValue)
                ?? ($dimensionKey->isTemporalPrimary() ? AnalysisDimensionGrain::Month : AnalysisDimensionGrain::Total);
        }

        return $dimensionKey->isTemporalPrimary()
            ? AnalysisDimensionGrain::Month
            : AnalysisDimensionGrain::Total;
    }
}
