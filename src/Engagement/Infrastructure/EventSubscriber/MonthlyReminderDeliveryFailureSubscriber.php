<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\EventSubscriber;

use App\Engagement\Application\MonthlyReminderNotificationType;
use App\Engagement\Domain\Entity\MonthlyReminderDispatch;
use App\Engagement\Domain\Enum\MonthlyReminderDispatchStatus;
use App\Engagement\Infrastructure\Repository\MonthlyReminderDispatchRepository;
use App\Notification\Application\Event\NotificationDeliveryFailed;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A reminder email that failed for good before it reached the mailer (recipient gone or disabled, delivery
 * job out of retries) leaves no mail event, so MonthlyReminderDeliverySubscriber never sees it. Without this,
 * the dispatch would stay queued and block the next scheduled attempt.
 *
 * The notification's parameters name the dispatch; it is only updated when it belongs to the same hospital
 * and is still queued, so a later attempt or an already completed dispatch is never overwritten.
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
        if (MonthlyReminderNotificationType::TYPE !== $event->type || !\is_int($dispatchId) || !\is_int($hospitalId)) {
            return;
        }

        $dispatch = $this->dispatchRepository->find($dispatchId);
        if (!$dispatch instanceof MonthlyReminderDispatch
            || $dispatch->getHospital()->getId() !== $hospitalId
            || MonthlyReminderDispatchStatus::Queued !== $dispatch->getStatus()) {
            return;
        }

        $dispatch->markFailed(\sprintf('notification_delivery:%s', $event->reasonCode));
        $this->dispatchRepository->save($dispatch);
    }
}
