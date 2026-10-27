<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter\Coverage;

/**
 * One column of the coverage calendar, Monday to Sunday.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template.
 */
final readonly class ImportCoverageWeek
{
    /**
     * @param list<ImportCoverageCell> $days
     * @param \DateTimeImmutable|null  $month first day of the month labelled above this week (UTC), if any
     */
    public function __construct(
        public array $days,
        public ?\DateTimeImmutable $month = null,
    ) {
    }
}
