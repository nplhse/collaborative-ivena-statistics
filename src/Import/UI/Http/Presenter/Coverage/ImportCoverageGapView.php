<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter\Coverage;

/**
 * @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template.
 */
final readonly class ImportCoverageGapView
{
    public function __construct(
        public string $firstDay,
        public string $lastDay,
        public int $days,
    ) {
    }
}
