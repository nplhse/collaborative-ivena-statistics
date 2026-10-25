<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\EventSubscriber;

use App\Engagement\Application\MonthlyReminderNotificationType;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Notification\Application\Event\NotificationDeliveryFailed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A reminder email that failed for good fails its dispatch, so it no longer blocks the next scheduled
 * attempt. This also covers failures before the mailer (owner disabled, delivery job out of retries),
 * which leave no mail event for MonthlyReminderDeliverySubscriber.
 *
 * Runs synchronously in the transaction that marks the delivery as failed: if this update fails, the delivery
 * stays pending and is retried. The notification's parameters name the dispatch, its hospital and the send
 * attempt; only a dispatch that is still queued for exactly that attempt changes, so a failure of an earlier
 * attempt never touches a newer one. The update is idempotent, which lets reconciliation repeat it.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
final readonly class MonthlyReminderDeliveryFailureSubscriber
{
    public function __construct(
        private MonthlyReminderDispatchRepository $dispatchRepository,
    ) {
    }

    #[AsEventListener]
    public function onDeliveryFailed(NotificationDeliveryFailed $event): void
    {
        $dispatchId = $event->parameters['dispatchId'] ?? null;
        $hospitalId = $event->parameters['hospitalId'] ?? null;
        $attemptId = $event->parameters['attemptId'] ?? null;
        if (MonthlyReminderNotificationType::TYPE !== $event->type || !\is_int($dispatchId) || !\is_int($hospitalId)
            || (null !== $attemptId && !\is_string($attemptId))) {
            return;
        }

        $this->dispatchRepository->markAttemptFailed($dispatchId, $hospitalId, $attemptId, \sprintf('notification_delivery:%s', $event->reasonCode));
    }
}
