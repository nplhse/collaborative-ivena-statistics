<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

final readonly class AnalysisResultRow
{
    public function __construct(
        public int|string|float|null $bucket,
        public int $value,
        public int|string|float|null $series = null,
    ) {
    }
}
