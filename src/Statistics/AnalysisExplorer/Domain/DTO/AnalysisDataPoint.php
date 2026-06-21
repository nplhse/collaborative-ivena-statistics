<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

final readonly class AnalysisDataPoint
{
    public function __construct(
        public string $bucket,
        public string $label,
        public int $value,
    ) {
    }
}
