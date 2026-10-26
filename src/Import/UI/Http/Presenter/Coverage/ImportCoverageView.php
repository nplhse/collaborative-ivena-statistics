<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter\Coverage;

/**
 * Data coverage card of the import detail page. Dates are preformatted (`d.m.Y`, `d.m.Y H:i`)
 * because they are naive wall-clock values that must not be shifted by the Twig timezone.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template.
 */
final readonly class ImportCoverageView
{
    /** Gaps shown before the rest is collapsed. */
    public const int VISIBLE_GAPS = 10;

    /**
     * @param list<ImportCoverageGapView> $gaps  all potential gaps, in chronological order
     * @param list<ImportCoverageWeek>    $weeks calendar columns
     */
    public function __construct(
        public ImportCoverageState $state,
        public bool $detectsGaps = false,
        public int $records = 0,
        public ?string $firstAt = null,
        public ?string $lastAt = null,
        public ?string $firstDay = null,
        public ?string $lastDay = null,
        public int $periodDays = 0,
        public int $daysWithData = 0,
        public int $daysWithoutData = 0,
        public array $gaps = [],
        public array $weeks = [],
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the import detail template. */
    public function isAvailable(): bool
    {
        return ImportCoverageState::Available === $this->state;
    }

    /** @psalm-suppress PossiblyUnusedMethod Called by the import detail template. */
    public function hasGaps(): bool
    {
        return $this->detectsGaps && [] !== $this->gaps;
    }

    /**
     * Calendar days without records in all gaps together.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the import detail template.
     */
    public function gapDays(): int
    {
        return array_sum(array_map(static fn (ImportCoverageGapView $gap): int => $gap->days, $this->gaps));
    }

    /**
     * @return list<ImportCoverageGapView>
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the import detail template.
     */
    public function visibleGaps(): array
    {
        return \array_slice($this->gaps, 0, self::VISIBLE_GAPS);
    }

    /**
     * @return list<ImportCoverageGapView>
     *
     * @psalm-suppress PossiblyUnusedMethod Called by the import detail template.
     */
    public function collapsedGaps(): array
    {
        return \array_slice($this->gaps, self::VISIBLE_GAPS);
    }
}
