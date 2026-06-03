<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

final readonly class GenericAnalysisGroupedTableRow
{
    /**
     * @param array<string, GenericAnalysisGroupedSeriesCell|null> $cellsBySeriesKey
     */
    public function __construct(
        public string $bucketKey,
        public string $bucketLabel,
        public array $cellsBySeriesKey,
        public int $bucketTotal,
    ) {
    }
}
