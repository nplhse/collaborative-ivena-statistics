<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisPresetException;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenericAnalysisConfigResolver
{
    public function __construct(
        private AnalysisPresetRegistry $presetRegistry,
        private DimensionRegistry $dimensionRegistry,
        private GenericAnalysisDimensionPolicy $dimensionPolicy,
        private GenericAnalysisMetricRequestResolver $metricRequestResolver,
        private TranslatorInterface $translator,
    ) {
    }

    public function resolve(
        string $presetKey,
        Request $request,
        StatisticsScopeCriteria $scopeCriteria,
        StatisticsPeriodBounds $periodBounds,
        StatisticsFilter $filter,
        ?User $user,
    ): ResolvedGenericAnalysisConfig {
        $routePreset = $this->presetRegistry->get($presetKey);

        $overridePrimary = $this->queryString($request, GenericAnalysisQueryKeys::PRIMARY);
        $overrideSeries = $this->querySeries($request);
        $overrideIncludeNull = $this->queryIncludeNull($request);
        $referencePresetKey = $this->queryString($request, GenericAnalysisQueryKeys::REF_PRESET);

        $hasExplicitOverrides = $request->query->has(GenericAnalysisQueryKeys::PRIMARY)
            || $request->query->has(GenericAnalysisQueryKeys::SERIES)
            || $request->query->has(GenericAnalysisQueryKeys::INCLUDE_NULL);

        $isCustomRoute = GenericAnalysisQueryKeys::PRESET_CUSTOM === $presetKey;

        if ($isCustomRoute || ($hasExplicitOverrides && $this->differsFromPreset(
            $routePreset,
            $overridePrimary,
            $overrideSeries,
            $overrideIncludeNull,
        ))) {
            return $this->resolveCustom(
                $routePreset,
                $referencePresetKey,
                $overridePrimary,
                $overrideSeries,
                $overrideIncludeNull,
                $scopeCriteria,
                $periodBounds,
                $isCustomRoute,
                $filter,
                $user,
                $request,
            );
        }

        $this->assertDimensionKey($routePreset->primaryDimensionKey, $filter, $user);
        if (null !== $routePreset->seriesDimensionKey) {
            $this->assertDimensionKey($routePreset->seriesDimensionKey, $filter, $user);
        }

        $query = $this->buildQuery(
            $request,
            $routePreset,
            $routePreset->primaryDimensionKey,
            $routePreset->seriesDimensionKey,
            $routePreset->includeNullBuckets,
            $scopeCriteria,
            $periodBounds,
        );

        return new ResolvedGenericAnalysisConfig(
            query: $query,
            displayTitle: $routePreset->title,
            isCustom: false,
            routePresetKey: $routePreset->key,
            referencePresetKey: null,
            primaryDimensionKey: $routePreset->primaryDimensionKey,
            seriesDimensionKey: $routePreset->seriesDimensionKey,
            includeNullBuckets: $routePreset->includeNullBuckets,
        );
    }

    public function findMatchingSelectablePreset(
        string $primaryDimensionKey,
        ?string $seriesDimensionKey,
        bool $includeNullBuckets,
    ): ?AnalysisPreset {
        foreach ($this->presetRegistry->selectable() as $preset) {
            if ($this->presetMatches($preset, $primaryDimensionKey, $seriesDimensionKey, $includeNullBuckets)) {
                return $preset;
            }
        }

        return null;
    }

    private function resolveCustom(
        AnalysisPreset $routePreset,
        ?string $referencePresetKey,
        ?string $overridePrimary,
        ?string $overrideSeries,
        ?bool $overrideIncludeNull,
        StatisticsScopeCriteria $scopeCriteria,
        StatisticsPeriodBounds $periodBounds,
        bool $isCustomRoute,
        StatisticsFilter $filter,
        ?User $user,
        Request $request,
    ): ResolvedGenericAnalysisConfig {
        $basePreset = $this->resolveBasePreset($routePreset, $referencePresetKey);

        $primaryKey = $overridePrimary ?? $basePreset->primaryDimensionKey;
        $seriesKey = $overrideSeries ?? $basePreset->seriesDimensionKey;
        $includeNull = $overrideIncludeNull ?? $basePreset->includeNullBuckets;

        $this->assertDimensionKey($primaryKey, $filter, $user);
        if (null !== $seriesKey && '' !== $seriesKey) {
            $this->assertDimensionKey($seriesKey, $filter, $user);
        } else {
            $seriesKey = null;
        }

        $referenceKey = $this->normalizeReferencePresetKey($referencePresetKey, $routePreset, $isCustomRoute);

        $query = $this->buildQuery(
            $request,
            $basePreset,
            $primaryKey,
            $seriesKey,
            $includeNull,
            $scopeCriteria,
            $periodBounds,
        );

        return new ResolvedGenericAnalysisConfig(
            query: $query,
            displayTitle: $this->translator->trans('stats.generic_analysis.custom_title'),
            isCustom: true,
            routePresetKey: GenericAnalysisQueryKeys::PRESET_CUSTOM,
            referencePresetKey: $referenceKey,
            primaryDimensionKey: $primaryKey,
            seriesDimensionKey: $seriesKey,
            includeNullBuckets: $includeNull,
        );
    }

    private function buildQuery(
        Request $request,
        AnalysisPreset $metricPreset,
        string $primaryKey,
        ?string $seriesKey,
        bool $includeNull,
        StatisticsScopeCriteria $scopeCriteria,
        StatisticsPeriodBounds $periodBounds,
    ): AnalysisQuery {
        $draftQuery = new AnalysisQuery(
            primaryDimensionKey: $primaryKey,
            scopeCriteria: $scopeCriteria,
            periodBounds: $periodBounds,
            seriesDimensionKey: $seriesKey,
            includeNullBuckets: $includeNull,
        );

        $metricKeys = $this->metricRequestResolver->resolveMetricKeys($request, $draftQuery, $metricPreset);
        $visualMetricKey = $this->metricRequestResolver->resolveVisualMetricKey(
            $request,
            $metricKeys,
            $metricPreset->visualMetricKey,
        );

        return new AnalysisQuery(
            primaryDimensionKey: $primaryKey,
            scopeCriteria: $scopeCriteria,
            periodBounds: $periodBounds,
            seriesDimensionKey: $seriesKey,
            metricKeys: $metricKeys,
            visualMetricKey: $visualMetricKey,
            includeNullBuckets: $includeNull,
        );
    }

    private function resolveBasePreset(
        AnalysisPreset $routePreset,
        ?string $referencePresetKey,
    ): AnalysisPreset {
        if (null !== $referencePresetKey && GenericAnalysisQueryKeys::PRESET_CUSTOM !== $referencePresetKey) {
            try {
                return $this->presetRegistry->get($referencePresetKey);
            } catch (UnknownAnalysisPresetException) {
            }
        }

        return $routePreset;
    }

    private function normalizeReferencePresetKey(
        ?string $referencePresetKey,
        AnalysisPreset $routePreset,
        bool $isCustomRoute,
    ): ?string {
        if (null !== $referencePresetKey && GenericAnalysisQueryKeys::PRESET_CUSTOM !== $referencePresetKey && $this->presetRegistry->has($referencePresetKey)) {
            return $referencePresetKey;
        }

        if ($isCustomRoute) {
            return null;
        }

        if (GenericAnalysisQueryKeys::PRESET_CUSTOM !== $routePreset->key) {
            return $routePreset->key;
        }

        return null;
    }

    private function differsFromPreset(
        AnalysisPreset $preset,
        ?string $overridePrimary,
        ?string $overrideSeries,
        ?bool $overrideIncludeNull,
    ): bool {
        if (GenericAnalysisQueryKeys::PRESET_CUSTOM === $preset->key) {
            return true;
        }

        if (null !== $overridePrimary && $overridePrimary !== $preset->primaryDimensionKey) {
            return true;
        }

        if (null !== $overrideSeries && $overrideSeries !== ($preset->seriesDimensionKey ?? '')) {
            return true;
        }

        return null !== $overrideIncludeNull && $overrideIncludeNull !== $preset->includeNullBuckets;
    }

    private function presetMatches(
        AnalysisPreset $preset,
        string $primaryDimensionKey,
        ?string $seriesDimensionKey,
        bool $includeNullBuckets,
    ): bool {
        return $preset->primaryDimensionKey === $primaryDimensionKey
            && $preset->seriesDimensionKey === $seriesDimensionKey
            && $preset->includeNullBuckets === $includeNullBuckets;
    }

    private function assertDimensionKey(string $key, StatisticsFilter $filter, ?User $user): void
    {
        if (!$this->dimensionRegistry->has($key)) {
            throw UnknownAnalysisDimensionException::forKey($key);
        }

        if (!$this->dimensionPolicy->isAllowed($key, $filter, $user)) {
            throw UnknownAnalysisDimensionException::notAllowedForScope($key);
        }
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    private function querySeries(Request $request): ?string
    {
        if (!$request->query->has(GenericAnalysisQueryKeys::SERIES)) {
            return null;
        }

        $value = $request->query->get(GenericAnalysisQueryKeys::SERIES);
        if (!\is_string($value) || '' === $value) {
            return '';
        }

        return $value;
    }

    private function queryIncludeNull(Request $request): ?bool
    {
        if (!$request->query->has(GenericAnalysisQueryKeys::INCLUDE_NULL)) {
            return null;
        }

        return '1' === (string) $request->query->get(GenericAnalysisQueryKeys::INCLUDE_NULL);
    }
}
