<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves metric keys and visual metric from HTTP query parameters.
 */
final readonly class GenericAnalysisMetricRequestResolver
{
    public function __construct(
        private MetricRegistry $metricRegistry,
        private DimensionRegistry $dimensionRegistry,
        private MetricCompatibilityChecker $metricCompatibilityChecker,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolveMetricKeys(Request $request, AnalysisQuery $draftQuery, AnalysisPreset $preset): array
    {
        $requested = $this->queryMetricKeys($request);
        if (null === $requested) {
            return $preset->metricKeys;
        }

        return $this->normalizeRequestedMetrics($draftQuery, $requested);
    }

    /**
     * @param list<string> $metricKeys
     */
    public function resolveVisualMetricKey(
        Request $request,
        array $metricKeys,
        ?string $presetVisualMetricKey,
        AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
    ): ?string {
        $resolvedKeys = [] === $metricKeys ? [$dataSource->defaultMetricKey()] : $metricKeys;
        $override = $this->queryString($request, GenericAnalysisQueryKeys::VISUAL_METRIC);
        if (null !== $override && \in_array($override, $resolvedKeys, true)) {
            return $override;
        }

        if (null !== $presetVisualMetricKey && \in_array($presetVisualMetricKey, $resolvedKeys, true)) {
            return $presetVisualMetricKey;
        }

        return null;
    }

    public function hasMetricOverrides(Request $request): bool
    {
        return $request->query->has(GenericAnalysisQueryKeys::METRICS)
            || $request->query->has(GenericAnalysisQueryKeys::VISUAL_METRIC);
    }

    /**
     * @param list<string> $requestedKeys
     *
     * @return list<string>
     */
    public function normalizeRequestedMetrics(AnalysisQuery $draftQuery, array $requestedKeys): array
    {
        $baseMetricKey = $draftQuery->dataSource->defaultMetricKey();
        $keys = [$baseMetricKey];

        foreach ($requestedKeys as $key) {
            if ('' === $key || $key === $baseMetricKey || 'count' === $key || 'hospital_count' === $key) {
                continue;
            }

            if (!$this->metricRegistry->has($key)) {
                continue;
            }

            $metric = $this->metricRegistry->get($key);
            $primary = $this->dimensionRegistry->get($draftQuery->primaryDimensionKey);
            $series = null !== $draftQuery->seriesDimensionKey
                ? $this->dimensionRegistry->get($draftQuery->seriesDimensionKey)
                : null;
            $result = $this->metricCompatibilityChecker->check($draftQuery, $primary, $series, $metric);

            if (!$result->allowed) {
                continue;
            }

            $keys[] = $key;
        }

        return $this->sortMetricKeys(array_values(array_unique($keys)));
    }

    /**
     * @return list<string>|null
     */
    private function queryMetricKeys(Request $request): ?array
    {
        if (!$request->query->has(GenericAnalysisQueryKeys::METRICS)) {
            return null;
        }

        $raw = array_values($request->query->all(GenericAnalysisQueryKeys::METRICS));

        return array_values(array_filter(
            $raw,
            static fn (mixed $value): bool => \is_string($value) && '' !== $value,
        ));
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function sortMetricKeys(array $keys): array
    {
        usort(
            $keys,
            fn (string $a, string $b): int => $this->metricRegistry->get($a)->sortPriority
                <=> $this->metricRegistry->get($b)->sortPriority,
        );

        return $keys;
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
