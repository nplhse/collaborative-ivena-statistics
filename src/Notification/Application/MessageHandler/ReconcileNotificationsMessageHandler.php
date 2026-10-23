<?php

declare(strict_types=1);

namespace App\Notification\Application\MessageHandler;

use App\Notification\Application\Message\ReconcileNotificationsMessage;
use App\Notification\Application\Reconciliation\NotificationReconciliation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReconcileNotificationsMessageHandler
{
    private const string LOCK_KEY = 'notification-reconciliation';

    public function __construct(
        private NotificationReconciliation $reconciliation,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcileNotificationsMessage $message): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY);
        if (!$lock->acquire()) {
            $this->logger->info('notification.reconciliation.skipped', ['reason' => 'already_running', 'message' => $message::class]);

            return;
        }

        try {
            $this->logger->info('notification.reconciliation.finished', [
                'published' => $this->reconciliation->reconcileRecent(),
                'deliveries_resumed' => $this->reconciliation->resumePendingDeliveries(),
            ]);
        } finally {
            $lock->release();
        }
    }
}
