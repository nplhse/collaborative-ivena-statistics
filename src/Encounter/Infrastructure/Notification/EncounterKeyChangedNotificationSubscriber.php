<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Notification;

use App\Encounter\Application\Event\EncounterKeyChanged;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the administrators about a key change (`admin.encounter_key_changed`, ADR 026). One entry per
 * hospital and key generation.
 *
 * @psalm-suppress UnusedClass Registered as an event listener.
 */
#[AsEventListener(event: EncounterKeyChanged::class, method: 'onKeyChanged')]
final readonly class EncounterKeyChangedNotificationSubscriber
{
    public function __construct(
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function onKeyChanged(EncounterKeyChanged $event): void
    {
        $recipientIds = $this->recipients->recipientIds();
        if ([] === $recipientIds) {
            return;
        }

        $this->publisher->publish(new NotificationRequest(
            type: AdminEncounterKeyChangedNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%d:%d', AdminEncounterKeyChangedNotification::TYPE, $event->hospitalId, $event->keyGeneration),
            parameters: [
                'hospitalName' => $event->hospitalName,
                'username' => $event->actorUsername,
                'generation' => $event->keyGeneration,
                'option' => $event->option->value,
            ],
            action: new NotificationAction('app_encounter_key_change', ['id' => $event->hospitalId]),
            metadata: ['reference' => \sprintf('hospital:%d', $event->hospitalId)],
        ));
    }
}
