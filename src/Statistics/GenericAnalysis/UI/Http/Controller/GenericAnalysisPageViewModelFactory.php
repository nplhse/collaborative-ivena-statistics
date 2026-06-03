<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
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
    ): GenericAnalysisPageViewModel {
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

        $resetToPresetUrl = null;
        if ($config->isCustom && null !== $config->referencePresetKey) {
            $resetToPresetUrl = $this->navigationUrlBuilder->build(
                $request,
                self::ROUTE,
                ['presetKey' => $config->referencePresetKey],
                GenericAnalysisQueryKeys::REMOVE_CUSTOM,
            );
        }

        [$dimensionGroups, $showRestrictedDimensionsHint] = $this->buildDimensionGroups(
            $request->getLocale(),
            $filter,
            $user,
        );

        $selectedMetricKeys = $config->query->resolvedMetricKeys();
        $availableMetrics = $this->buildAvailableMetrics($config, $selectedMetricKeys);

        return new GenericAnalysisPageViewModel(
            presetMenu: $presetMenu,
            selectedPresetLabel: $selectedPresetLabel,
            dimensionGroups: $dimensionGroups,
            showRestrictedDimensionsHint: $showRestrictedDimensionsHint,
            formAction: $this->router->generate(
                self::ROUTE,
                ['presetKey' => $config->isCustom ? GenericAnalysisQueryKeys::PRESET_CUSTOM : $routePresetKey],
            ),
            preservedQueryFields: $this->buildPreservedQueryFields($request),
            formPrimary: $config->primaryDimensionKey,
            formSeries: $config->seriesDimensionKey ?? '',
            formIncludeNull: $config->includeNullBuckets,
            formReferencePreset: $config->referencePresetKey ?? ($config->isCustom ? null : $routePresetKey),
            isCustom: $config->isCustom,
            referencePresetTitle: $referencePresetTitle,
            resetToPresetUrl: $resetToPresetUrl,
            availableMetrics: $availableMetrics,
            visualMetricOptions: $this->buildVisualMetricOptions($availableMetrics, $config->query->resolvedVisualMetricKey()),
        );
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

    /**
     * @return array{0: list<array{type: string, label: string, options: list<array{key: string, label: string}>}>, 1: bool}
     */
    private function buildDimensionGroups(string $locale, StatisticsFilter $filter, ?User $user): array
    {
        $grouped = [];
        $hadRestricted = false;
        foreach ($this->dimensionRegistry->all() as $dimension) {
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
            $count = $this->metricRegistry->get('count');

            return [[
                'key' => 'count',
                'label' => $count->label,
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
            $metricKeys = array_values($request->query->all(GenericAnalysisQueryKeys::METRICS));
            foreach ($metricKeys as $metricKey) {
                if (!\is_string($metricKey) || '' === $metricKey || 'count' === $metricKey) {
                    continue;
                }
                $fields[] = [
                    'key' => GenericAnalysisQueryKeys::METRICS.'[]',
                    'value' => $metricKey,
                ];
            }
        }

        return $fields;
    }
}
