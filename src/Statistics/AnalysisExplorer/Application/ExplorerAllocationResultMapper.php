<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;

final readonly class ExplorerAllocationResultMapper
{
    public function __construct(
        private DimensionRegistry $dimensionRegistry,
        private AnalysisDimensionLabelResolver $labelResolver,
    ) {
    }

    /**
     * @return list<AnalysisResultRow>
     */
    public function map(AnalysisResult $result): array
    {
        $primary = $this->dimensionRegistry->get($result->primaryDimensionKey);
        $series = null !== $result->seriesDimensionKey
            ? $this->dimensionRegistry->get($result->seriesDimensionKey)
            : null;

        $this->labelResolver->warmEntityLabels($result, $primary, $series);

        $rows = [];
        foreach ($result->rows as $row) {
            $bucket = $row->bucket;
            if (null === $bucket || '' === $bucket) {
                continue;
            }

            $seriesValue = $row->series;
            if ($series instanceof AnalysisDimension && (null === $seriesValue || '' === $seriesValue)) {
                continue;
            }

            $bucketKey = (string) $bucket;
            $seriesKey = null;
            if (null !== $seriesValue && '' !== $seriesValue) {
                $seriesKey = (string) $seriesValue;
            }

            $rows[] = new AnalysisResultRow(
                bucket: $bucketKey,
                bucketLabel: $this->labelResolver->labelFor($primary, $bucket),
                seriesKey: $seriesKey,
                seriesLabel: $series instanceof AnalysisDimension && null !== $seriesKey
                    ? $this->labelResolver->labelFor($series, $seriesValue)
                    : null,
                value: $row->countValue(),
            );
        }

        return $rows;
    }
}
