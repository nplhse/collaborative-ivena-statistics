<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Notification;

use App\Content\Application\Event\ReactionChanged;
use App\Content\Application\Message\RefreshReactionNotificationMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Schedules the refresh of a target's grouped notification (#973). The delay absorbs a quick misclick; a failure here
 * must not turn a saved reaction into an error page.
 *
 * @psalm-suppress UnusedClass Registered as an event listener.
 */
final readonly class ReactionNotificationSubscriber
{
    public const int DELAY_MS = 30_000;

    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: ReactionChanged::class)]
    public function onReactionChanged(ReactionChanged $event): void
    {
        try {
            $this->bus->dispatch(
                new RefreshReactionNotificationMessage($event->targetType, $event->targetId),
                [new DelayStamp(self::DELAY_MS)],
            );
        } catch (\Throwable $exception) {
            $this->logger->error('reaction.notification_schedule_failed', [
                'target_type' => $event->targetType->value,
                'target_id' => $event->targetId,
                'exception' => $exception::class,
            ]);
        }
    }
}
