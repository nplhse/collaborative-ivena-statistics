<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Entity\DataRequestEvent;

/**
 * One entry of a data extract request's timeline: a group of events that belong together, or a top-level comment with
 * its replies. `event` is the first event of the group.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the template.
 */
final readonly class TimelineEntry
{
    /** @var list<DataRequestEvent> */
    public array $events;

    /**
     * @param list<DataRequestEvent> $events the whole group; defaults to the one event
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public ?DataRequestEvent $event = null,
        public ?DataRequestComment $comment = null,
        array $events = [],
    ) {
        $this->events = [] === $events && $event instanceof DataRequestEvent ? [$event] : $events;
    }

    /** The same entry with one more event in its group. */
    public function with(DataRequestEvent $event): self
    {
        return new self($this->at, $this->event, null, [...$this->events, $event]);
    }

    public function lastEvent(): ?DataRequestEvent
    {
        return [] === $this->events ? null : $this->events[array_key_last($this->events)];
    }
}
