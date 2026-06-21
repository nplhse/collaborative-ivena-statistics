<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

final readonly class AnalysisResultRow
{
    public function __construct(
        public string $bucket,
        public string $bucketLabel,
        public ?string $seriesKey,
        public ?string $seriesLabel,
        public int $value,
    ) {
    }

    public function hasSeries(): bool
    {
        return null !== $this->seriesKey;
    }
}
