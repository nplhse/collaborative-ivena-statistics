<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Whether an event closed a metric in a population (ADR 015).
 * Only `closed` and `partial` count as closure effects in burden and profiles.
 */
enum ClosureVolumeRelevance: string
{
    /** Closed in this department, or in every department of an aggregated population. */
    case Closed = 'closed';
    /** Closed in some departments of a speciality or hospital. */
    case Partial = 'partial';
    /** Not closed; calculated for comparison only. */
    case None = 'none';

    public function isClosureEffect(): bool
    {
        return self::None !== $this;
    }

    public static function forCount(int $closedDepartments, int $departments): self
    {
        if ($closedDepartments <= 0) {
            return self::None;
        }

        return $closedDepartments >= $departments ? self::Closed : self::Partial;
    }
}
