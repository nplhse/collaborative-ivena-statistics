<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestEvent;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;

/**
 * Builds the timeline of a data extract request for one reader (ADR 045): events and top-level comments in the order
 * they happened, without entries above the reader's visibility levels. Replies stay below their comment; the template
 * filters them with the same levels.
 *
 * Events that follow each other by the same person (or the system), with the same visibility and at most ten minutes
 * apart, become one entry, for example a saved query and its release. A comment in between ends the group.
 */
final class DataRequestTimeline
{
    public const int GROUP_GAP_SECONDS = 600;

    /**
     * @param list<Visibility> $readable
     *
     * @return list<TimelineEntry>
     */
    public static function build(DataRequest $request, array $readable): array
    {
        $entries = [];
        foreach ($request->getEvents() as $event) {
            if (\in_array($event->getVisibility(), $readable, true)) {
                $entries[] = new TimelineEntry($event->getOccurredAt(), event: $event);
            }
        }

        foreach ($request->getTopLevelComments() as $comment) {
            if (\in_array($comment->getVisibility(), $readable, true)) {
                $entries[] = new TimelineEntry($comment->getCreatedAt(), comment: $comment);
            }
        }

        // By time; within the same second a vote comes first, then the comment that gives its reason, then what followed
        // from it (for example the end of the round).
        usort($entries, static fn (TimelineEntry $a, TimelineEntry $b): int => [$a->at, self::rank($a)] <=> [$b->at, self::rank($b)]);

        return self::group($entries);
    }

    /**
     * @param list<TimelineEntry> $entries
     *
     * @return list<TimelineEntry>
     */
    private static function group(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $last = array_pop($grouped);
            $previous = $last?->lastEvent();
            if ($last instanceof TimelineEntry && $entry->event instanceof DataRequestEvent && $previous instanceof DataRequestEvent && self::belongTogether($previous, $entry->event)) {
                $grouped[] = $last->with($entry->event);

                continue;
            }

            if ($last instanceof TimelineEntry) {
                $grouped[] = $last;
            }

            $grouped[] = $entry;
        }

        return $grouped;
    }

    private static function belongTogether(DataRequestEvent $previous, DataRequestEvent $next): bool
    {
        return $previous->getActor()?->getId() === $next->getActor()?->getId()
            && $previous->getVisibility() === $next->getVisibility()
            && $next->getOccurredAt()->getTimestamp() - $previous->getOccurredAt()->getTimestamp() <= self::GROUP_GAP_SECONDS;
    }

    private static function rank(TimelineEntry $entry): int
    {
        if (!$entry->event instanceof DataRequestEvent) {
            return 1;
        }

        return TimelineEventType::Voted === $entry->event->getType() ? 0 : 2;
    }
}
