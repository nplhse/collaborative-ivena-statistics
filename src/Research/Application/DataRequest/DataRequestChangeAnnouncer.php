<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Application\Event\DataRequestChanged;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Entity\DataRequestEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Announces the timeline events and comments a service stored (ADR 045): `pending()` before the flush notes the
 * entries that are not stored yet, `announce()` after a successful flush dispatches DataRequestChanged with their ids.
 */
final readonly class DataRequestChangeAnnouncer
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return array{events: list<DataRequestEvent>, comments: list<DataRequestComment>}
     */
    public function pending(DataRequest $request): array
    {
        return [
            'events' => array_values(array_filter($request->getEvents(), static fn (DataRequestEvent $event): bool => null === $event->getId())),
            'comments' => array_values(array_filter($request->getComments(), static fn (DataRequestComment $comment): bool => null === $comment->getId())),
        ];
    }

    /**
     * @param array{events: list<DataRequestEvent>, comments: list<DataRequestComment>} $pending
     */
    public function announce(DataRequest $request, array $pending): void
    {
        $eventIds = array_values(array_filter(array_map(static fn (DataRequestEvent $event): ?int => $event->getId(), $pending['events'])));
        $commentIds = array_values(array_filter(array_map(static fn (DataRequestComment $comment): ?int => $comment->getId(), $pending['comments'])));
        $requestId = $request->getId();
        if (null === $requestId || ([] === $eventIds && [] === $commentIds)) {
            return;
        }

        $this->eventDispatcher->dispatch(new DataRequestChanged($requestId, $eventIds, $commentIds));
    }
}
