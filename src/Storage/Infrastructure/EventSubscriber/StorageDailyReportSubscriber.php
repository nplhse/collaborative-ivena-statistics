<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\EventSubscriber;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Storage\Application\Contract\StorageMeasurementRepositoryInterface;
use App\Storage\Application\Event\StorageMeasured;
use App\Storage\Application\Notification\StorageNotificationParameters;
use App\Storage\Domain\Entity\StorageMeasurement;
use App\Storage\Infrastructure\Notification\DiskUsagePage;
use App\Storage\Infrastructure\Notification\StorageDailyReportNotification;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Publishes `storage.daily_report` from the stored measurement (ADR 017, "Notifications"); it never scans
 * again. The change is relative to the latest earlier measurement within {@see self::PREVIOUS_WINDOW_DAYS}
 * days. The type is opt-in, so only administrators who switched it on receive it.
 */
#[AsEventListener(event: StorageMeasured::class, method: 'onStorageMeasured')]
final readonly class StorageDailyReportSubscriber
{
    public const int PREVIOUS_WINDOW_DAYS = 31;

    public function __construct(
        private StorageMeasurementRepositoryInterface $measurements,
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function onStorageMeasured(StorageMeasured $event): void
    {
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
            type: StorageDailyReportNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%s', StorageDailyReportNotification::TYPE, $measuredOn->format('Y-m-d')),
            parameters: StorageNotificationParameters::report($measurement, $this->previous($measuredOn)),
            action: new NotificationAction(DiskUsagePage::ROUTE),
            metadata: ['reference' => \sprintf('storage_measurement:%d', $event->measurementId)],
        ));
    }

    private function previous(\DateTimeImmutable $measuredOn): ?StorageMeasurement
    {
        $day = $measuredOn->format('Y-m-d');
        $earlier = array_filter(
            $this->measurements->findSince($measuredOn->modify(\sprintf('-%d days', self::PREVIOUS_WINDOW_DAYS))),
            static fn (StorageMeasurement $candidate): bool => $candidate->getMeasuredOn()->format('Y-m-d') < $day,
        );

        return [] === $earlier ? null : end($earlier);
    }
}
