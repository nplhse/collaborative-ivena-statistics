<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\DTO;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;

final readonly class AnalysisMatrix
{
    /**
     * @param list<string>                                                $orderedRowKeys
     * @param array<string, string>                                       $rowLabels
     * @param list<string>                                                $orderedColumnKeys
     * @param array<string, string>                                       $columnLabels
     * @param array<string, array<string, array<string, float|int|null>>> $cells             rowKey => colKey => metricValue => value
     */
    public function __construct(
        public AnalysisAxisRef $rowAxis,
        public ?AnalysisAxisRef $columnAxis,
        public array $orderedRowKeys,
        public array $rowLabels,
        public array $orderedColumnKeys,
        public array $columnLabels,
        public array $cells,
    ) {
    }

    public function hasColumnAxis(): bool
    {
        return [] !== $this->orderedColumnKeys;
    }

    public static function fromRunResult(AnalysisRunResult $result): self
    {
        $orderedRowKeys = [];
        $rowLabels = [];
        $orderedColumnKeys = [];
        $columnLabels = [];
        /** @var array<string, array<string, array<string, float|int|null>>> $cells */
        $cells = [];

        foreach ($result->rows as $row) {
            $rowKey = $row->bucket;
            if (!isset($rowLabels[$rowKey])) {
                $orderedRowKeys[] = $rowKey;
                $rowLabels[$rowKey] = $row->bucketLabel;
            }

            $colKey = $row->seriesKey ?? '';
            if ('' !== $colKey && !isset($columnLabels[$colKey])) {
                $orderedColumnKeys[] = $colKey;
                $columnLabels[$colKey] = $row->seriesLabel ?? $colKey;
            }

            foreach ($result->metricKeys as $metricKey) {
                $cells[$rowKey][$colKey][$metricKey->value] = $row->valueFor($metricKey);
            }
        }

        return new self(
            rowAxis: $result->rowAxis,
            columnAxis: $result->columnAxis,
            orderedRowKeys: $orderedRowKeys,
            rowLabels: $rowLabels,
            orderedColumnKeys: $orderedColumnKeys,
            columnLabels: $columnLabels,
            cells: $cells,
        );
    }

    public function valueFor(
        string $rowKey,
        string $columnKey,
        AnalysisMetricKey $metricKey,
    ): float {
        $value = $this->cells[$rowKey][$columnKey][$metricKey->value] ?? null;

        return null === $value ? 0.0 : (float) $value;
    }

    /**
     * @return list<array{name: string, data: list<float>}>
     */
    public function chartSeries(AnalysisMetricKey $visualMetricKey): array
    {
        $series = [];
        foreach ($this->orderedColumnKeys as $colKey) {
            $data = [];
            foreach ($this->orderedRowKeys as $rowKey) {
                $data[] = $this->valueFor($rowKey, $colKey, $visualMetricKey);
            }
            $series[] = [
                'name' => $this->columnLabels[$colKey],
                'data' => $data,
            ];
        }

        if ([] === $series) {
            $data = [];
            foreach ($this->orderedRowKeys as $rowKey) {
                $data[] = $this->valueFor($rowKey, '', $visualMetricKey);
            }

            return [['name' => '', 'data' => $data]];
        }

        return $series;
    }

    /**
     * @return list<string>
     */
    public function chartLabels(): array
    {
        return array_map(
            fn (string $rowKey): string => $this->rowLabels[$rowKey],
            $this->orderedRowKeys,
        );
    }

    /**
     * @return list<string>
     */
    public function heatmapColumnLabels(): array
    {
        return array_map(
            fn (string $colKey): string => $this->columnLabels[$colKey],
            $this->orderedColumnKeys,
        );
    }

    /**
     * @return list<list<float>>
     */
    public function heatmapMatrix(AnalysisMetricKey $visualMetricKey): array
    {
        $matrix = [];
        foreach ($this->orderedRowKeys as $rowKey) {
            $row = [];
            foreach ($this->orderedColumnKeys as $colKey) {
                $row[] = $this->valueFor($rowKey, $colKey, $visualMetricKey);
            }
            $matrix[] = $row;
        }

        return $matrix;
    }
}
