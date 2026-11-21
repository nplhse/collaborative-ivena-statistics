<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Notification;

use App\Content\Application\Event\MediaIntegrityChecked;
use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells the administrators about missing or damaged media files (`admin.media_integrity_problem`, ADR 039). The
 * deduplication key is the set of affected files, so the same problem is reported once and a new one again.
 *
 * @psalm-suppress UnusedClass Registered as an event listener.
 */
#[AsEventListener(event: MediaIntegrityChecked::class, method: 'onChecked')]
final readonly class MediaIntegrityNotificationSubscriber
{
    private const string MEDIA_LIBRARY_ROUTE = 'app_admin_dashboard_media_index';

    public function __construct(
        private AdminNotificationRecipientResolverInterface $recipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    public function onChecked(MediaIntegrityChecked $event): void
    {
        $report = $event->report;
        if (!$report->hasProblems()) {
            return;
        }

        $recipientIds = $this->recipients->recipientIds();
        if ([] === $recipientIds) {
            return;
        }

        $affected = [...array_map(static fn (string $key): string => 'missing:'.$key, $report->missing), ...array_map(static fn (string $key): string => 'damaged:'.$key, $report->damaged)];
        sort($affected);

        $this->publisher->publish(new NotificationRequest(
            type: AdminMediaIntegrityNotification::TYPE,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%s', AdminMediaIntegrityNotification::TYPE, hash('sha256', implode("\n", $affected))),
            parameters: [
                'missing' => \count($report->missing),
                'damaged' => \count($report->damaged),
            ],
            action: new NotificationAction(self::MEDIA_LIBRARY_ROUTE),
            metadata: ['reference' => 'media_integrity_check'],
        ));
    }
}
