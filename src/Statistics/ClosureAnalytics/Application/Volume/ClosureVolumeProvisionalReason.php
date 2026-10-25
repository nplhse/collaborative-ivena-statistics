<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

/**
 * Why an event's results can still change (ADR 015): the reference window after the event
 * is not yet covered by allocation imports, or not yet by closure imports.
 */
enum ClosureVolumeProvisionalReason: string
{
    case AwaitingAllocations = 'awaiting_allocations';
    case AwaitingClosures = 'awaiting_closures';

    /**
     * Allocations are checked first: without them the reference itself is missing.
     * Coverage that is not derived yet cannot confirm anything, so the event stays provisional.
     */
    public static function forEvent(
        \DateTimeImmutable $eventEnd,
        ClosureVolumeReferenceWindow $window,
        ?ClosureCoverage $allocations,
        ?ClosureCoverage $closures,
    ): ?self {
        $until = $eventEnd->modify(sprintf('+%d weeks', $window->weeksAfter));
        if (!$allocations instanceof ClosureCoverage || !$allocations->covers($eventEnd, $until)) {
            return self::AwaitingAllocations;
        }
        if (!$closures instanceof ClosureCoverage || !$closures->covers($eventEnd, $until)) {
            return self::AwaitingClosures;
        }

        return null;
    }
}
