<?php

declare(strict_types=1);

namespace App\Encounter\Application\Event;

/**
 * An encounter import run inserted new visits (#1048). MatchAfterImportListener queues a matcher run for
 * the affected hospital-days (#1053).
 *
 * Dispatched once per successful run with at least one inserted visit, after the run has committed.
 *
 * @psalm-suppress PossiblyUnusedProperty importId is for listeners that need the import.
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
