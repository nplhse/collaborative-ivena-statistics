<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Model;

/**
 * Neutral distribution payload for charts and tables (labels + series + raw table rows).
 *
 * @phpstan-type SeriesRow array{name: string, values: list<int>, percentages: list<float>}
 * @phpstan-type TableRow array{primaryLabel: string, groupLabel: ?string, count: int, percent: float}
 *
 * @psalm-suppress PossiblyUnusedProperty $tableRows is read from the DistributionPanel Twig template.
 */
final readonly class DistributionPanelView
{
    /**
     * @param list<string>    $labels
     * @param list<SeriesRow> $series
     * @param list<TableRow>  $tableRows
     */
    public function __construct(
        public array $labels,
        public array $series,
        public array $tableRows,
        public bool $grouped,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Consumed by Twig (DistributionPanel). */
    public function hasData(): bool
    {
        return array_any($this->series, fn ($s): bool => array_sum($s['values']) > 0);
    }
}
