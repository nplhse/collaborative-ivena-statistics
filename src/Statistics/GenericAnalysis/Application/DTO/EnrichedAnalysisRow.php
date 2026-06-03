<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\DTO;

final readonly class EnrichedAnalysisRow
{
    public function __construct(
        public string $bucketKey,
        public string $bucketLabel,
        public int $value,
        public float $percentOfTotal,
        public float $percentOfBucket,
        public ?string $seriesKey = null,
        public ?string $seriesLabel = null,
    ) {
    }
}
