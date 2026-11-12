<?php

declare(strict_types=1);

namespace App\Encounter\Application\Event;

/**
 * An encounter import run inserted new visits (#1048). The matcher (#1053) listens to it and links the
 * affected hospital-days; until then nothing consumes it.
 *
 * Dispatched once per successful run with at least one inserted visit, after the run has committed.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the matcher (#1053).
 */
final readonly class EncountersImported
{
    /**
     * @param list<string> $days calendar days (Y-m-d, Europe/Berlin) of the inserted visits' time anchors, ascending
     */
    public function __construct(
        public int $hospitalId,
        public int $importId,
        public array $days,
    ) {
    }
}
