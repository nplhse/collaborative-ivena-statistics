<?php

declare(strict_types=1);

namespace App\User\Infrastructure\EventSubscriber;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Event\UserBecameParticipant;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Puts "your participation is active" into the user's inbox. Every activation is a new event, so the
 * deduplication key contains its time: activating a user again after a revocation notifies again.
 */
#[AsEventListener(event: UserBecameParticipant::class, method: 'onUserBecameParticipant')]
final readonly class ParticipationActivatedNotificationSubscriber
{
    public function __construct(
        private NotificationPublisherInterface $publisher,
        private ClockInterface $clock,
    ) {
    }

    public function onUserBecameParticipant(UserBecameParticipant $event): void
    {
        $this->publisher->publish(new NotificationRequest(
            type: 'participation.activated',
            recipientUserIds: [$event->userId],
            deduplicationKey: \sprintf('participation.activated:%d:%s', $event->userId, $this->clock->now()->format('YmdHis')),
            action: new NotificationAction('app_default'),
        ));
    }
}
