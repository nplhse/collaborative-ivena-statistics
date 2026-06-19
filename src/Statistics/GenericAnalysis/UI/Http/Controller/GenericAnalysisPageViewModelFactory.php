<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDisplayMode;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisPeriodAppliesTo;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisSeriesMode;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;
use App\Statistics\GenericAnalysis\Domain\HospitalAnalysisConstants;
use App\Statistics\GenericAnalysis\Registry\AnalysisDataSourceRegistry;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisRouteContext;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenericAnalysisPageViewModelFactory
{
    private const string ROUTE = 'app_stats_generic_analysis';

    /** @var list<string> */
    private const array PRESERVED_QUERY_KEYS = [
        StatisticsQueryKeys::SCOPE,
        StatisticsQueryKeys::HOSPITAL,
        StatisticsQueryKeys::COHORT,
        StatisticsQueryKeys::STATE,
        StatisticsQueryKeys::DISPATCH_AREA,
        StatisticsQueryKeys::PERIOD,
        StatisticsQueryKeys::YEAR,
        StatisticsQueryKeys::MONTH,
        StatisticsQueryKeys::QUARTER,
        GenericAnalysisQueryKeys::LAYOUT,
    ];

    public function __construct(
        private AnalysisPresetRegistry $presetRegistry,
        private DimensionRegistry $dimensionRegistry,
        private MetricRegistry $metricRegistry,
        private MetricCompatibilityChecker $metricCompatibilityChecker,
        private GenericAnalysisDimensionPolicy $dimensionPolicy,
        private AnalysisDataSourceRegistry $dataSourceRegistry,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(
        Request $request,
        string $routePresetKey,
        ResolvedGenericAnalysisConfig $config,
        StatisticsFilter $filter,
        ?User $user,
        ?GenericAnalysisRouteContext $routeContext = null,
        ?string $launchViewKey = null,
    ): GenericAnalysisPageViewModel {
        $routeContext ??= GenericAnalysisRouteContext::forPreset($routePresetKey);
        $presetMenu = [];
        foreach ($this->presetRegistry->selectable() as $preset) {
            $presetMenu[] = [
                'key' => $preset->key,
                'title' => $preset->title,
                'url' => $this->navigationUrlBuilder->build(
                    $request,
                    self::ROUTE,
                    ['presetKey' => $preset->key],
                    GenericAnalysisQueryKeys::REMOVE_CUSTOM,
                ),
                'active' => !$config->isCustom && $preset->key === $routePresetKey,
            ];
        }

        $selectedPresetLabel = $config->isCustom
            ? $this->translator->trans('stats.generic_analysis.custom_title')
            : $this->presetRegistry->get($routePresetKey)->title;

        $referencePresetTitle = null;
        if (null !== $config->referencePresetKey && $this->presetRegistry->has($config->referencePresetKey)) {
            $referencePresetTitle = $this->presetRegistry->get($config->referencePresetKey)->title;
        }

        [$dimensionGroups, $showRestrictedDimensionsHint] = $this->buildDimensionGroups(
            $request->getLocale(),
            $filter,
            $user,
            $config->query->dataSource,
        );

        $dataSourceDefinition = $this->dataSourceRegistry->get($config->query->dataSource);
        $baseMetricKey = $dataSourceDefinition->defaultMetricKey();
        $selectedMetricKeys = $config->query->resolvedMetricKeys();
        $availableMetrics = $this->buildAvailableMetrics($config, $selectedMetricKeys);
        $hospitalPopulationOptions = $this->buildHospitalPopulationOptions(
            $request,
            $routeContext,
            $config->query->hospitalPopulationMode,
        );
        $dataSourceOptions = $this->buildDataSourceOptions(
            $request,
            $routeContext,
            $config->query->dataSource,
        );

        return new GenericAnalysisPageViewModel(
            presetMenu: $presetMenu,
            selectedPresetLabel: $selectedPresetLabel,
            dimensionGroups: $dimensionGroups,
            showRestrictedDimensionsHint: $showRestrictedDimensionsHint,
            formAction: $this->router->generate(
                $routeContext->routeName,
                $routeContext->routeParams,
            ),
            preservedQueryFields: $this->buildPreservedQueryFields($request),
            formPrimary: $config->primaryDimensionKey,
            formSeries: $config->seriesDimensionKey ?? '',
            formVisualMetric: $config->query->resolvedVisualMetricKey(),
            formIncludeNull: $config->includeNullBuckets,
            formReferencePreset: $config->referencePresetKey ?? ($config->isCustom ? null : $routePresetKey),
            isCustom: $config->isCustom,
            referencePresetTitle: $referencePresetTitle,
            resetToPresetUrl: $this->buildResetUrl($request, $config, $routeContext),
            availableMetrics: $availableMetrics,
            visualMetricOptions: $this->buildVisualMetricOptions($availableMetrics, $config->query->resolvedVisualMetricKey()),
            saveTitleDraft: $this->buildSaveTitleDraft(
                $config->primaryDimensionKey,
                $config->seriesDimensionKey,
                $config->query->resolvedVisualMetricKey(),
            ),
            formSeriesMode: $config->query->seriesMode->value,
            formChartType: $config->query->chartType?->value ?? '',
            formDisplayMode: $config->query->displayMode->value,
            seriesModeOptions: $this->buildSeriesModeOptions($config->query->seriesMode),
            chartTypeOptions: $this->buildChartTypeOptions(),
            displayModeOptions: $this->buildDisplayModeOptions($config->query->displayMode),
            defaultBaseMetricKey: $baseMetricKey,
            formDataSource: $config->query->dataSource->value,
            formHospitalPopulation: $config->query->hospitalPopulationMode->value,
            showDataSourceSelector: $config->isCustom
                || AnalysisDataSource::Hospitals === $config->query->dataSource
                || $routeContext->isBuilder(),
            showHospitalPopulationControl: $dataSourceDefinition->supportsPopulationModifier,
            showPeriodAppliesHint: AnalysisPeriodAppliesTo::AllocationDerivedOnly === $dataSourceDefinition->periodAppliesTo,
            dataSourceOptions: $dataSourceOptions,
            dataSourceHeaderTabs: $this->buildDataSourceHeaderTabs($dataSourceOptions, $routeContext),
            hospitalPopulationOptions: $hospitalPopulationOptions,
            launchFormAction: null !== $launchViewKey
                ? $this->router->generate(
                    GenericAnalysisRouteContext::ANALYTICS_VIEW_ROUTE,
                    ['viewKey' => $launchViewKey],
                )
                : null,
        );
    }

    public function buildSaveTitleDraft(
        string $primaryDimensionKey,
        ?string $seriesDimensionKey,
        string $visualMetricKey,
    ): string {
        $primaryLabel = $this->dimensionRegistry->get($primaryDimensionKey)->label;

        if (null !== $seriesDimensionKey && '' !== $seriesDimensionKey) {
            return $this->translator->trans('stats.generic_analysis.chart.subtitle_with_series', [
                'primary' => $primaryLabel,
                'series' => $this->dimensionRegistry->get($seriesDimensionKey)->label,
            ]);
        }

        return $this->translator->trans('stats.analytics_library.save.title_draft', [
            'metric' => $this->metricRegistry->get($visualMetricKey)->label,
            'primary' => $primaryLabel,
        ]);
    }

    public function buildPresetRedirectUrl(Request $request, string $presetKey): string
    {
        return $this->navigationUrlBuilder->build(
            $request,
            self::ROUTE,
            ['presetKey' => $presetKey],
            GenericAnalysisQueryKeys::REMOVE_CUSTOM,
        );
    }

    private function buildResetUrl(
        Request $request,
        ResolvedGenericAnalysisConfig $config,
        GenericAnalysisRouteContext $routeContext,
    ): ?string {
        if (!$config->isCustom) {
            return $this->navigationUrlBuilder->build(
                $request,
                $routeContext->routeName,
                $routeContext->routeParams,
                GenericAnalysisQueryKeys::REMOVE_CUSTOM,
            );
        }

        if (null === $config->referencePresetKey) {
            return null;
        }

        if (GenericAnalysisRouteContext::ANALYTICS_VIEW_ROUTE === $routeContext->routeName) {
            return $this->navigationUrlBuilder->build(
                $request,
                $routeContext->routeName,
                ['viewKey' => $config->referencePresetKey],
                GenericAnalysisQueryKeys::REMOVE_CUSTOM,
            );
        }

        return $this->navigationUrlBuilder->build(
            $request,
            self::ROUTE,
            ['presetKey' => $config->referencePresetKey],
            GenericAnalysisQueryKeys::REMOVE_CUSTOM,
        );
    }

    /**
     * @return array{0: list<array{type: string, label: string, options: list<array{key: string, label: string}>}>, 1: bool}
     */
    private function buildDimensionGroups(
        string $locale,
        StatisticsFilter $filter,
        ?User $user,
        AnalysisDataSource $dataSource,
    ): array {
        $grouped = [];
        $hadRestricted = false;
        foreach ($this->dimensionRegistry->forDataSource($dataSource) as $dimension) {
            if (HospitalAnalysisConstants::POPULATION_GROUP_DIMENSION_KEY === $dimension->key) {
                continue;
            }

            if (!$this->dimensionPolicy->isAllowed($dimension->key, $filter, $user)) {
                $hadRestricted = true;

                continue;
            }

            $typeKey = $dimension->type->value;
            $grouped[$typeKey]['type'] = $typeKey;
            $grouped[$typeKey]['label'] = $this->dimensionTypeLabel($dimension->type, $locale);
            $grouped[$typeKey]['options'][] = [
                'key' => $dimension->key,
                'label' => $dimension->label,
            ];
        }

        $order = [
            AnalysisDimensionType::Temporal->value,
            AnalysisDimensionType::Categorical->value,
            AnalysisDimensionType::Boolean->value,
            AnalysisDimensionType::Numeric->value,
        ];

        $groups = [];
        foreach ($order as $type) {
            if (isset($grouped[$type])) {
                $groups[] = $grouped[$type];
            }
        }

        return [$groups, $hadRestricted];
    }

    private function dimensionTypeLabel(AnalysisDimensionType $type, string $locale): string
    {
        return match ($type) {
            AnalysisDimensionType::Temporal => $this->translator->trans('stats.generic_analysis.dimension_type.temporal', locale: $locale),
            AnalysisDimensionType::Categorical => $this->translator->trans('stats.generic_analysis.dimension_type.categorical', locale: $locale),
            AnalysisDimensionType::Boolean => $this->translator->trans('stats.generic_analysis.dimension_type.boolean', locale: $locale),
            AnalysisDimensionType::Numeric => $this->translator->trans('stats.generic_analysis.dimension_type.numeric', locale: $locale),
        };
    }

    /**
     * @param list<string> $selectedMetricKeys
     *
     * @return list<array{key: string, label: string, allowed: bool, reason: ?string, selected: bool, sortPriority: int}>
     */
    private function buildAvailableMetrics(ResolvedGenericAnalysisConfig $config, array $selectedMetricKeys): array
    {
        $items = [];
        foreach ($this->metricCompatibilityChecker->listAvailability($config->query) as $entry) {
            $metric = $entry['metric'];
            $items[] = [
                'key' => $metric->key,
                'label' => $metric->label,
                'allowed' => $entry['allowed'],
                'reason' => $entry['reason'],
                'selected' => \in_array($metric->key, $selectedMetricKeys, true),
                'sortPriority' => $metric->sortPriority,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['sortPriority'] <=> $b['sortPriority']);

        return $items;
    }

    /**
     * @param list<array{key: string, label: string, allowed: bool, reason: ?string, selected: bool, sortPriority: int}> $availableMetrics
     *
     * @return list<array{key: string, label: string, selected: bool}>
     */
    private function buildVisualMetricOptions(array $availableMetrics, string $selectedVisualMetricKey): array
    {
        $options = [];
        foreach ($availableMetrics as $metric) {
            if (!$metric['selected']) {
                continue;
            }

            $options[] = [
                'key' => $metric['key'],
                'label' => $metric['label'],
                'selected' => $metric['key'] === $selectedVisualMetricKey,
            ];
        }

        if ([] === $options) {
            $defaultMetric = $this->metricRegistry->get($availableMetrics[0]['key'] ?? 'count');

            return [[
                'key' => $defaultMetric->key,
                'label' => $defaultMetric->label,
                'selected' => true,
            ]];
        }

        return $options;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function buildPreservedQueryFields(Request $request): array
    {
        $fields = [];
        foreach (self::PRESERVED_QUERY_KEYS as $key) {
            if (!$request->query->has($key)) {
                continue;
            }
            $fields[] = ['key' => $key, 'value' => $request->query->getString($key)];
        }

        if ($request->query->has(GenericAnalysisQueryKeys::VISUAL_METRIC)) {
            $fields[] = [
                'key' => GenericAnalysisQueryKeys::VISUAL_METRIC,
                'value' => $request->query->getString(GenericAnalysisQueryKeys::VISUAL_METRIC),
            ];
        }

        if ($request->query->has(GenericAnalysisQueryKeys::TOP)) {
            $fields[] = [
                'key' => GenericAnalysisQueryKeys::TOP,
                'value' => $request->query->getString(GenericAnalysisQueryKeys::TOP),
            ];
        }

        if ($request->query->has(GenericAnalysisQueryKeys::METRICS)) {
            $dataSource = AnalysisDataSource::tryFrom($request->query->getString(GenericAnalysisQueryKeys::DATA_SOURCE))
                ?? AnalysisDataSource::Allocations;
            $baseMetricKey = $this->dataSourceRegistry->get($dataSource)->defaultMetricKey();
            $metricKeys = array_values($request->query->all(GenericAnalysisQueryKeys::METRICS));
            foreach ($metricKeys as $metricKey) {
                if (!\is_string($metricKey) || '' === $metricKey || $metricKey === $baseMetricKey) {
                    continue;
                }
                $fields[] = [
                    'key' => GenericAnalysisQueryKeys::METRICS.'[]',
                    'value' => $metricKey,
                ];
            }
        }

        foreach ([
            GenericAnalysisQueryKeys::CHART,
            GenericAnalysisQueryKeys::SERIES_MODE,
            GenericAnalysisQueryKeys::DISPLAY,
            GenericAnalysisQueryKeys::DATA_SOURCE,
            GenericAnalysisQueryKeys::HOSPITAL_POPULATION,
        ] as $key) {
            if ($request->query->has($key)) {
                $fields[] = ['key' => $key, 'value' => $request->query->getString($key)];
            }
        }

        if ($request->query->has(GenericAnalysisQueryKeys::CHART_METRICS)) {
            foreach (array_values($request->query->all(GenericAnalysisQueryKeys::CHART_METRICS)) as $metricKey) {
                if (!\is_string($metricKey) || '' === $metricKey) {
                    continue;
                }
                $fields[] = [
                    'key' => GenericAnalysisQueryKeys::CHART_METRICS.'[]',
                    'value' => $metricKey,
                ];
            }
        }

        return $fields;
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function buildSeriesModeOptions(AnalysisSeriesMode $selected): array
    {
        return [
            [
                'value' => AnalysisSeriesMode::ByDimension->value,
                'label' => $this->translator->trans('stats.generic_analysis.series_mode.by_dimension'),
                'selected' => AnalysisSeriesMode::ByDimension === $selected,
            ],
            [
                'value' => AnalysisSeriesMode::ByMetric->value,
                'label' => $this->translator->trans('stats.generic_analysis.series_mode.by_metric'),
                'selected' => AnalysisSeriesMode::ByMetric === $selected,
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildChartTypeOptions(): array
    {
        $options = [];
        foreach ([
            GenericAnalysisChartType::Line,
            GenericAnalysisChartType::Bar,
            GenericAnalysisChartType::StackedBar,
            GenericAnalysisChartType::GroupedBar,
            GenericAnalysisChartType::HorizontalBar,
            GenericAnalysisChartType::PercentStackedBar,
            GenericAnalysisChartType::Pie,
            GenericAnalysisChartType::Heatmap,
        ] as $type) {
            $options[] = [
                'value' => $type->value,
                'label' => $this->translator->trans(match ($type) {
                    GenericAnalysisChartType::Line => 'stats.generic_analysis.chart.type.line',
                    GenericAnalysisChartType::Bar => 'stats.generic_analysis.chart.type.bar',
                    GenericAnalysisChartType::StackedBar => 'stats.generic_analysis.chart.type.stacked_bar',
                    GenericAnalysisChartType::GroupedBar => 'stats.generic_analysis.chart.type.grouped_bar',
                    GenericAnalysisChartType::HorizontalBar => 'stats.generic_analysis.chart.type.horizontal_bar',
                    GenericAnalysisChartType::PercentStackedBar => 'stats.generic_analysis.chart.type.percent_stacked_bar',
                    GenericAnalysisChartType::Pie => 'stats.generic_analysis.chart.type.pie',
                    GenericAnalysisChartType::Heatmap => 'stats.generic_analysis.chart.type.heatmap',
                    default => 'stats.generic_analysis.chart.type.bar',
                }),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function buildDisplayModeOptions(AnalysisDisplayMode $selected): array
    {
        return [
            [
                'value' => AnalysisDisplayMode::Chart->value,
                'label' => $this->translator->trans('stats.generic_analysis.display_mode.chart'),
                'selected' => AnalysisDisplayMode::Chart === $selected,
            ],
            [
                'value' => AnalysisDisplayMode::Table->value,
                'label' => $this->translator->trans('stats.generic_analysis.display_mode.table'),
                'selected' => AnalysisDisplayMode::Table === $selected,
            ],
            [
                'value' => AnalysisDisplayMode::PivotTable->value,
                'label' => $this->translator->trans('stats.generic_analysis.display_mode.pivot_table'),
                'selected' => AnalysisDisplayMode::PivotTable === $selected,
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string, selected: bool, url: string}>
     */
    private function buildHospitalPopulationOptions(
        Request $request,
        GenericAnalysisRouteContext $routeContext,
        HospitalPopulationMode $selected,
    ): array {
        $options = [];
        foreach ([
            HospitalPopulationMode::All,
            HospitalPopulationMode::Participating,
            HospitalPopulationMode::Compare,
        ] as $mode) {
            $options[] = [
                'value' => $mode->value,
                'label' => $this->translator->trans(match ($mode) {
                    HospitalPopulationMode::All => 'stats.generic_analysis.hospital_population.all',
                    HospitalPopulationMode::Participating => 'stats.generic_analysis.hospital_population.participating',
                    HospitalPopulationMode::Compare => 'stats.generic_analysis.hospital_population.compare',
                }),
                'selected' => $mode === $selected,
                'url' => $this->navigationUrlBuilder->build(
                    $request,
                    $routeContext->routeName,
                    array_merge(
                        $routeContext->routeParams,
                        [GenericAnalysisQueryKeys::HOSPITAL_POPULATION => $mode->value],
                    ),
                    HospitalPopulationMode::Compare === $mode ? [GenericAnalysisQueryKeys::SERIES] : [],
                ),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, selected: bool, url?: string}>
     */
    private function buildDataSourceOptions(
        Request $request,
        GenericAnalysisRouteContext $routeContext,
        AnalysisDataSource $selected,
    ): array {
        $options = [];
        foreach ($this->dataSourceRegistry->all() as $definition) {
            $option = [
                'value' => $definition->source->value,
                'label' => $this->translator->trans($definition->labelTranslationKey),
                'selected' => $definition->source === $selected,
            ];

            if ($routeContext->usesDataSourceNavigationUrls()) {
                $option['url'] = $this->navigationUrlBuilder->build(
                    $request,
                    $routeContext->routeName,
                    array_merge(
                        $routeContext->routeParams,
                        [GenericAnalysisQueryKeys::DATA_SOURCE => $definition->source->value],
                    ),
                    GenericAnalysisQueryKeys::REMOVE_CUSTOM,
                );
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * @param list<array{value: string, label: string, selected: bool, url?: string}> $dataSourceOptions
     *
     * @return list<array{key: string, label: string, url: string, active: bool, testId: string}>
     */
    private function buildDataSourceHeaderTabs(
        array $dataSourceOptions,
        GenericAnalysisRouteContext $routeContext,
    ): array {
        if (!$routeContext->isBuilder()) {
            return [];
        }

        $tabs = [];
        foreach ($dataSourceOptions as $option) {
            if (!isset($option['url'])) {
                continue;
            }

            $tabs[] = [
                'key' => $option['value'],
                'label' => $option['label'],
                'url' => $option['url'],
                'active' => $option['selected'],
                'testId' => 'stats-analytics-data-source-'.$option['value'],
            ];
        }

        return $tabs;
    }
}
