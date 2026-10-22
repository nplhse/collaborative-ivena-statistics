<?php

declare(strict_types=1);

namespace App\Notification\Application\MessageHandler;

use App\Notification\Application\Contract\NotificationRetentionInterface;
use App\Notification\Application\Message\CleanUpNotificationsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanUpNotificationsMessageHandler
{
    private const string LOCK_KEY = 'notification-retention';

    public function __construct(
        private NotificationRetentionInterface $retention,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CleanUpNotificationsMessage $message): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY);
        if (!$lock->acquire()) {
            $this->logger->info('notification.retention.skipped', ['reason' => 'already_running', 'message' => $message::class]);

            return;
        }

        try {
            $result = $this->retention->run();

            $this->logger->info('notification.retention.finished', [
                'deleted_notifications' => $result->deletedNotifications,
                'deleted_deduplication_keys' => $result->deletedDeduplicationKeys,
                'corrected_recipients' => $result->correctedRecipients,
            ]);
        } finally {
            $lock->release();
        }
    }
}
