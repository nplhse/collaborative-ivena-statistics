<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain\DTO;

/**
 * Placeholder for future period-over-period or scope comparisons.
 */
final readonly class AnalysisComparison
{
    public function __construct(
        public ?string $mode = null,
    ) {
    }
}
