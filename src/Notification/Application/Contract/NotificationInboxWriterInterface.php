<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

/**
 * Read and removal changes on a recipient's own entries. Each visible change updates the recipient's
 * version and unread counter in the same transaction.
 */
interface NotificationInboxWriterInterface
{
    /**
     * @return bool whether the entry changed from unread to read
     *
     * @psalm-suppress PossiblyUnusedReturnValue Production callers ignore the result; tests assert it.
     */
    public function markRead(int $recipientId, int $notificationId): bool;

    /**
     * @return int number of entries that changed from unread to read
     *
     * @psalm-suppress PossiblyUnusedReturnValue Production callers ignore the result; tests assert it.
     */
    public function markAllRead(int $recipientId): int;

    /**
     * Hides the entry at once; outstanding deliveries are not cancelled.
     *
     * @return bool whether the entry was removed by this call
     *
     * @psalm-suppress PossiblyUnusedReturnValue Production callers ignore the result; tests assert it.
     */
    public function remove(int $recipientId, int $notificationId): bool;
}
