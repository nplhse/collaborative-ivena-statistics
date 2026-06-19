<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\Infrastructure\Repository\SavedAnalysisViewRepository;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisViewResolver
{
    public function __construct(
        private AnalysisViewRegistry $viewRegistry,
        private GenericAnalysisConfigResolver $configResolver,
        private SavedAnalysisViewRepository $savedViewRepository,
    ) {
    }

    public function resolveSystemView(
        string $viewKey,
        Request $request,
        StatisticsScopeCriteria $scopeCriteria,
        StatisticsPeriodBounds $periodBounds,
        StatisticsFilter $filter,
        ?User $user,
    ): ResolvedAnalysisViewContext {
        if (!$this->viewRegistry->has($viewKey)) {
            throw UnknownAnalysisViewException::forKey($viewKey);
        }

        $view = $this->viewRegistry->get($viewKey);
        $config = $this->configResolver->resolve(
            $view->presetKey(),
            $request,
            $scopeCriteria,
            $periodBounds,
            $filter,
            $user,
        );

        $config = $this->withViewQueryDefaults($config, $view, $request);

        return new ResolvedAnalysisViewContext(
            view: $view,
            config: $this->withViewDisplay($config, $view),
            sourceKey: $view->key,
            isSaved: false,
        );
    }

    public function resolveSavedView(
        int $savedViewId,
        Request $request,
        StatisticsScopeCriteria $scopeCriteria,
        StatisticsPeriodBounds $periodBounds,
        StatisticsFilter $filter,
        ?User $user,
    ): ResolvedAnalysisViewContext {
        $saved = $this->savedViewRepository->findForOwner($savedViewId, $user);
        if (!$saved instanceof \App\Statistics\Domain\Entity\SavedAnalysisView) {
            throw UnknownAnalysisViewException::forKey((string) $savedViewId);
        }

        $viewConfig = $saved->getConfig();
        $baseView = null;
        $sourceSystemViewKey = $saved->getSourceSystemViewKey();
        if (null !== $sourceSystemViewKey && $this->viewRegistry->has($sourceSystemViewKey)) {
            $baseView = $this->viewRegistry->get($sourceSystemViewKey);
        }

        $presetKey = $baseView?->presetKey() ?? 'custom';
        $config = $this->configResolver->resolve(
            $presetKey,
            $this->applySavedConfigToRequest($request, $viewConfig),
            $scopeCriteria,
            $periodBounds,
            $filter,
            $user,
        );

        $savedId = $saved->getId();
        if (null === $savedId) {
            throw new \LogicException('Saved analysis view must be persisted before use.');
        }

        $displayView = new AnalysisViewDefinition(
            key: 'saved_'.$savedId,
            title: $saved->getTitle(),
            description: $saved->getDescription() ?? '',
            category: $baseView instanceof AnalysisViewDefinition ? $baseView->category : \App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory::TimeAndTrends,
            tags: $baseView instanceof AnalysisViewDefinition ? $baseView->tags : [],
            primaryDimensionKey: $viewConfig->primaryDimensionKey,
            secondaryDimensionKey: $viewConfig->secondaryDimensionKey,
            metricKeys: $viewConfig->metricKeys,
            visualMetricKey: $viewConfig->visualMetricKey,
            chartType: null !== $viewConfig->chartType
                ? GenericAnalysisChartType::from($viewConfig->chartType)
                : ($baseView instanceof AnalysisViewDefinition ? $baseView->chartType : GenericAnalysisChartType::Bar),
            allowedChartTypes: $baseView instanceof AnalysisViewDefinition ? $baseView->allowedChartTypes : [],
            includeNullBuckets: $viewConfig->includeNullBuckets,
            legacyPresetKey: $sourceSystemViewKey,
        );

        return new ResolvedAnalysisViewContext(
            view: $displayView,
            config: $this->withViewDisplay($config, $displayView),
            sourceKey: (string) $saved->getId(),
            isSaved: true,
            savedViewId: $saved->getId(),
        );
    }

    private function withViewQueryDefaults(
        ResolvedGenericAnalysisConfig $config,
        AnalysisViewDefinition $view,
        Request $request,
    ): ResolvedGenericAnalysisConfig {
        $query = $config->query;
        $patchedQuery = new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery(
            primaryDimensionKey: $query->primaryDimensionKey,
            scopeCriteria: $query->scopeCriteria,
            periodBounds: $query->periodBounds,
            seriesDimensionKey: $query->seriesDimensionKey,
            metricKeys: [] === $query->metricKeys ? $view->resolvedMetricKeys() : $query->metricKeys,
            visualMetricKey: $query->visualMetricKey ?? $view->visualMetricKey,
            filters: $query->filters,
            includeNullBuckets: $query->includeNullBuckets,
            seriesMode: $request->query->has(GenericAnalysisQueryKeys::SERIES_MODE)
                ? $query->seriesMode
                : $view->seriesMode,
            chartType: $request->query->has(GenericAnalysisQueryKeys::CHART)
                ? $query->chartType
                : $view->chartType,
            displayMode: $request->query->has(GenericAnalysisQueryKeys::DISPLAY)
                ? $query->displayMode
                : $view->displayMode,
            chartMetricKeys: $query->chartMetricKeys,
            configVersion: $query->configVersion,
            dataSource: $request->query->has(GenericAnalysisQueryKeys::DATA_SOURCE)
                ? $query->dataSource
                : $view->dataSource,
            hospitalPopulationMode: $request->query->has(GenericAnalysisQueryKeys::HOSPITAL_POPULATION)
                ? $query->hospitalPopulationMode
                : $view->hospitalPopulationMode,
        );

        return new ResolvedGenericAnalysisConfig(
            query: $patchedQuery,
            displayTitle: $config->displayTitle,
            isCustom: $config->isCustom,
            routePresetKey: $config->routePresetKey,
            referencePresetKey: $config->referencePresetKey,
            primaryDimensionKey: $config->primaryDimensionKey,
            seriesDimensionKey: $config->seriesDimensionKey,
            includeNullBuckets: $config->includeNullBuckets,
        );
    }

    private function withViewDisplay(
        ResolvedGenericAnalysisConfig $config,
        AnalysisViewDefinition $view,
    ): ResolvedGenericAnalysisConfig {
        if ($config->isCustom) {
            return $config;
        }

        return new ResolvedGenericAnalysisConfig(
            query: $config->query,
            displayTitle: $view->title,
            isCustom: $config->isCustom,
            routePresetKey: $config->routePresetKey,
            referencePresetKey: $config->referencePresetKey ?? $view->key,
            primaryDimensionKey: $config->primaryDimensionKey,
            seriesDimensionKey: $config->seriesDimensionKey,
            includeNullBuckets: $config->includeNullBuckets,
        );
    }

    private function applySavedConfigToRequest(Request $request, AnalysisViewConfig $viewConfig): Request
    {
        $query = $request->query->all();
        $query[GenericAnalysisQueryKeys::PRIMARY] = $viewConfig->primaryDimensionKey;
        $query[GenericAnalysisQueryKeys::SERIES] = $viewConfig->secondaryDimensionKey ?? '';
        $query[GenericAnalysisQueryKeys::INCLUDE_NULL] = $viewConfig->includeNullBuckets ? '1' : '0';
        $query[GenericAnalysisQueryKeys::METRICS] = $viewConfig->resolvedMetricKeys();
        if (null !== $viewConfig->visualMetricKey) {
            $query[GenericAnalysisQueryKeys::VISUAL_METRIC] = $viewConfig->visualMetricKey;
        }
        if (null !== $viewConfig->layout) {
            $query[GenericAnalysisQueryKeys::LAYOUT] = $viewConfig->layout;
        }
        if (null !== $viewConfig->top) {
            $query[GenericAnalysisQueryKeys::TOP] = (string) $viewConfig->top;
        }
        if (null !== $viewConfig->chartType) {
            $query[GenericAnalysisQueryKeys::CHART] = $viewConfig->chartType;
        }
        $query[GenericAnalysisQueryKeys::SERIES_MODE] = $viewConfig->seriesMode;
        $query[GenericAnalysisQueryKeys::DISPLAY] = $viewConfig->displayMode;
        if ([] !== $viewConfig->chartMetricKeys) {
            $query[GenericAnalysisQueryKeys::CHART_METRICS] = $viewConfig->chartMetricKeys;
        }
        $query[GenericAnalysisQueryKeys::DATA_SOURCE] = $viewConfig->dataSource->value;
        $query[GenericAnalysisQueryKeys::HOSPITAL_POPULATION] = $viewConfig->hospitalPopulationMode;

        return $request->duplicate(query: $query);
    }
}
