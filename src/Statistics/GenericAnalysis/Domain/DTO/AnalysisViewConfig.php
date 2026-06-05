<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisFilterOperator;

/**
 * Serialisable analysis configuration for saved user views.
 */
final readonly class AnalysisViewConfig
{
    /**
     * @param list<string>         $metricKeys
     * @param list<AnalysisFilter> $filters
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
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolvedMetricKeys(): array
    {
        if ([] === $this->metricKeys) {
            return ['count'];
        }

        return $this->metricKeys;
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

        return new self(
            primaryDimensionKey: (string) ($data['primaryDimensionKey'] ?? 'month'),
            secondaryDimensionKey: isset($data['secondaryDimensionKey']) && '' !== $data['secondaryDimensionKey']
                ? (string) $data['secondaryDimensionKey']
                : null,
            metricKeys: isset($data['metricKeys']) && \is_array($data['metricKeys'])
                ? array_values(array_map(strval(...), $data['metricKeys']))
                : [],
            visualMetricKey: isset($data['visualMetricKey']) ? (string) $data['visualMetricKey'] : null,
            chartType: isset($data['chartType']) ? (string) $data['chartType'] : null,
            includeNullBuckets: (bool) ($data['includeNullBuckets'] ?? false),
            filters: $filters,
            layout: isset($data['layout']) ? (string) $data['layout'] : null,
            top: isset($data['top']) ? (int) $data['top'] : null,
        );
    }
}
