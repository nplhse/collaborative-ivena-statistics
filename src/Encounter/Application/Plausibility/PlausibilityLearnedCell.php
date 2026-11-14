<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use App\Encounter\Domain\Enum\PlausibilityCellSource;

/**
 * A cell estimated by the learning job (ADR 033): an exact cell (PZC × diagram) or a fallback cell (problem area ×
 * diagram) with its counts over all hospitals and its points. Below the minimum observations a cell keeps its seed
 * points (source `curated`); only then `$estimate` is null.
 *
 * The counts and the estimate stay internal (ADR 033, Privacy): they are written to the cell and read by the lookup
 * rule and the tests, never shown on a page.
 *
 * @psalm-suppress PossiblyUnusedProperty The estimate is read by the tests to check the arithmetic before rounding.
 */
final readonly class PlausibilityLearnedCell
{
    public function __construct(
        public ?int $pzc,
        public ?string $areaCode,
        public string $diagramCode,
        public int $points,
        public PlausibilityCellSource $source,
        public int $positives,
        public int $negatives,
        /** Shrunk log2 likelihood ratio before rounding and clipping; null when the cell keeps its seed points. */
        public ?float $estimate = null,
    ) {
    }

    public function observations(): int
    {
        return $this->positives + $this->negatives;
    }
}
