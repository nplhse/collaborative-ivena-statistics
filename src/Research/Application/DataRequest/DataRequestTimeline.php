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
 */
final class DataRequestTimeline
{
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

        return $entries;
    }

    private static function rank(TimelineEntry $entry): int
    {
        if (!$entry->event instanceof DataRequestEvent) {
            return 1;
        }

        return TimelineEventType::Voted === $entry->event->getType() ? 0 : 2;
    }
}
