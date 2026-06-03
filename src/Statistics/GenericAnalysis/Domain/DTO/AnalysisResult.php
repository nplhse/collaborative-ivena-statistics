<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

final readonly class AnalysisResult
{
    /**
     * @param list<AnalysisResultRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $grandTotal,
        public string $primaryDimensionKey,
        public ?string $seriesDimensionKey = null,
        public bool $includeNullBuckets = false,
    ) {
    }
}
