<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDisplayMode;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisSeriesMode;
use App\Statistics\GenericAnalysis\Domain\Enum\HospitalPopulationMode;

/**
 * Serialisable analysis configuration for saved user views.
 */
final readonly class AnalysisViewConfig
{
    /**
     * @param list<string>         $metricKeys
     * @param list<AnalysisFilter> $filters
     * @param list<string>         $chartMetricKeys
     */
    public function __construct(
        public string $primaryDimensionKey,
        public ?string $secondaryDimensionKey = null,
        public array $metricKeys = [],
        public ?string $visualMetricKey = null,
        public ?string $chartType = null,
        public bool $includeNullBuckets = false,
        public array $filters = [],
        public ?string $layout = null,
        public ?int $top = null,
        public string $seriesMode = AnalysisSeriesMode::ByDimension->value,
        public string $displayMode = AnalysisDisplayMode::Chart->value,
        public array $chartMetricKeys = [],
        public int $configVersion = 3,
        public AnalysisDataSource $dataSource = AnalysisDataSource::Allocations,
        public string $hospitalPopulationMode = HospitalPopulationMode::All->value,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolvedMetricKeys(): array
    {
        if ([] === $this->metricKeys) {
            return [$this->dataSource->defaultMetricKey()];
        }

        return $this->metricKeys;
    }

    public function resolvedHospitalPopulationMode(): HospitalPopulationMode
    {
        return HospitalPopulationMode::fromRequestValue($this->hospitalPopulationMode);
    }

    public function resolvedSeriesMode(): AnalysisSeriesMode
    {
        return AnalysisSeriesMode::tryFrom($this->seriesMode) ?? AnalysisSeriesMode::ByDimension;
    }

    public function resolvedDisplayMode(): AnalysisDisplayMode
    {
        return AnalysisDisplayMode::tryFrom($this->displayMode) ?? AnalysisDisplayMode::Chart;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primaryDimensionKey' => $this->primaryDimensionKey,
            'secondaryDimensionKey' => $this->secondaryDimensionKey,
            'metricKeys' => $this->metricKeys,
            'visualMetricKey' => $this->visualMetricKey,
            'chartType' => $this->chartType,
            'includeNullBuckets' => $this->includeNullBuckets,
            'filters' => array_map(
                static fn (AnalysisFilter $filter): array => [
                    'dimensionKey' => $filter->dimensionKey,
                    'operator' => $filter->operator->value,
                    'value' => $filter->value,
                ],
                $this->filters,
            ),
            'layout' => $this->layout,
            'top' => $this->top,
            'seriesMode' => $this->seriesMode,
            'displayMode' => $this->displayMode,
            'chartMetricKeys' => $this->chartMetricKeys,
            'configVersion' => $this->configVersion,
            'dataSource' => $this->dataSource->value,
            'hospitalPopulationMode' => $this->hospitalPopulationMode,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $filters = [];
        if (isset($data['filters']) && \is_array($data['filters'])) {
            foreach ($data['filters'] as $filterData) {
                if (!\is_array($filterData)) {
                    continue;
                }
                $operator = AnalysisFilterOperator::tryFrom((string) ($filterData['operator'] ?? ''))
                    ?? AnalysisFilterOperator::Equals;
                $filters[] = new AnalysisFilter(
                    dimensionKey: (string) ($filterData['dimensionKey'] ?? ''),
                    operator: $operator,
                    value: $filterData['value'] ?? '',
                );
            }
        }

        $metricKeys = isset($data['metricKeys']) && \is_array($data['metricKeys'])
            ? array_values(array_map(strval(...), $data['metricKeys']))
            : [];

        $dataSource = AnalysisDataSource::tryFrom((string) ($data['dataSource'] ?? ''))
            ?? AnalysisDataSource::Allocations;
        $hospitalPopulationMode = HospitalPopulationMode::fromRequestValue(
            (string) ($data['hospitalPopulationMode'] ?? HospitalPopulationMode::All->value),
        );
        $seriesMode = self::resolveSeriesModeFromLegacy($data, $metricKeys, $dataSource);
        $configVersion = isset($data['configVersion']) ? (int) $data['configVersion'] : 1;

        $chartMetricKeys = isset($data['chartMetricKeys']) && \is_array($data['chartMetricKeys'])
            ? array_values(array_map(strval(...), $data['chartMetricKeys']))
            : [];

        return new self(
            primaryDimensionKey: (string) ($data['primaryDimensionKey'] ?? 'month'),
            secondaryDimensionKey: isset($data['secondaryDimensionKey']) && '' !== $data['secondaryDimensionKey']
                ? (string) $data['secondaryDimensionKey']
                : null,
            metricKeys: $metricKeys,
            visualMetricKey: isset($data['visualMetricKey']) ? (string) $data['visualMetricKey'] : null,
            chartType: isset($data['chartType']) ? (string) $data['chartType'] : null,
            includeNullBuckets: (bool) ($data['includeNullBuckets'] ?? false),
            filters: $filters,
            layout: isset($data['layout']) ? (string) $data['layout'] : null,
            top: isset($data['top']) ? (int) $data['top'] : null,
            seriesMode: $seriesMode,
            displayMode: isset($data['displayMode']) ? (string) $data['displayMode'] : AnalysisDisplayMode::Chart->value,
            chartMetricKeys: $chartMetricKeys,
            configVersion: max(2, $configVersion),
            dataSource: $dataSource,
            hospitalPopulationMode: $hospitalPopulationMode->value,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $metricKeys
     */
    private static function resolveSeriesModeFromLegacy(
        array $data,
        array $metricKeys,
        AnalysisDataSource $dataSource,
    ): string {
        if (isset($data['seriesMode']) && \is_string($data['seriesMode']) && '' !== $data['seriesMode']) {
            return $data['seriesMode'];
        }

        $secondary = $data['secondaryDimensionKey'] ?? null;
        $hasSecondary = \is_string($secondary) && '' !== $secondary;
        $resolvedMetrics = [] === $metricKeys ? [$dataSource->defaultMetricKey()] : $metricKeys;

        if (!$hasSecondary && \count($resolvedMetrics) > 1) {
            return AnalysisSeriesMode::ByMetric->value;
        }

        return AnalysisSeriesMode::ByDimension->value;
    }
}
