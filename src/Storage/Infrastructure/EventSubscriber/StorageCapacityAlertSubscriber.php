<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\EventSubscriber;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Storage\Application\Contract\StorageCapacityAlertStoreInterface;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\Event\StorageMeasured;
use App\Storage\Application\Notification\CapacityLevelChange;
use App\Storage\Application\Notification\StorageNotificationParameters;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Infrastructure\Notification\DiskUsagePage;
use App\Storage\Infrastructure\Notification\StorageCapacityAlertNotification;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Sends `storage.capacity_alert` when the measured level differs from the last notified one
 * (ADR 017, "Notifications"), then stores the new level so the next change alerts again. An unchanged
 * or unknown level sends nothing and changes nothing. The key makes reruns on the same day harmless.
 */
#[AsEventListener(event: StorageMeasured::class, method: 'onStorageMeasured')]
final readonly class StorageCapacityAlertSubscriber
{
    public function __construct(
        private StorageCapacityAlertStoreInterface $alertStore,
        private StorageMeasurementRepositoryInterface $measurements,
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
        private ClockInterface $clock,
    ) {
    }

    public function onStorageMeasured(StorageMeasured $event): void
    {
        $change = CapacityLevelChange::between($this->alertStore->lastNotifiedLevel(), $event->level);
        if (!$change instanceof CapacityLevelChange) {
            return;
        }

        $measurement = $this->measurements->findForDay($event->measuredOn);
        if (!$measurement instanceof StorageMeasurement || $measurement->getId() !== $event->measurementId) {
            return;
        }

        $recipientIds = $this->recipients->recipientIds();
        if ([] === $recipientIds) {
            return;
        }

        $measuredOn = $measurement->getMeasuredOn();
        $this->publisher->publish(new NotificationRequest(
            type: StorageCapacityAlertNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf(
                '%s:%s-%s:%s',
                StorageCapacityAlertNotification::TYPE,
                $change->from->value,
                $change->to->value,
                $measuredOn->format('Y-m-d'),
            ),
            parameters: StorageNotificationParameters::alert($measurement, $change),
            action: new NotificationAction(DiskUsagePage::ROUTE),
            metadata: ['reference' => \sprintf('storage_measurement:%d', $event->measurementId)],
        ));

        $this->alertStore->recordNotified($change->to, $measuredOn, $this->clock->now());
    }
}
