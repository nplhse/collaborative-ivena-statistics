<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Presenter\Coverage;

/**
 * One day of the coverage calendar. Days before the first or after the last record only pad
 * the first and last week and are rendered blank.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template.
 */
final readonly class ImportCoverageCell
{
    public const string DATA = 'data';

    /** A day without records inside the covered period of an allocation import. */
    public const string GAP = 'gap';

    /** A day without records where none are expected, e.g. without closures. */
    public const string NONE = 'none';

    public const string OUTSIDE = 'outside';

    /** Colour steps for days with records, like GitHub's contribution calendar. */
    public const int LEVELS = 4;

    /**
     * @param self::DATA|self::GAP|self::NONE|self::OUTSIDE $kind
     * @param int<0, 4>                                     $level 1..4 relative to the busiest day, 0 without records
     */
    public function __construct(
        public string $day,
        public string $kind,
        public int $count = 0,
        public int $level = 0,
    ) {
    }
}
