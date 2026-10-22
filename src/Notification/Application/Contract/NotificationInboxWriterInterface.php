<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

/**
 * Read and removal changes on a recipient's own entries. Ids of other recipients are ignored.
 * Each visible change updates the recipient's version and unread counter in the same transaction.
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
     * @param list<int> $notificationIds
     *
     * @return int number of entries that changed from unread to read
     */
    public function markReadMany(int $recipientId, array $notificationIds): int;

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

    /**
     * @param list<int> $notificationIds
     *
     * @return int number of entries removed by this call
     */
    public function removeMany(int $recipientId, array $notificationIds): int;

    /**
     * Hides every entry of the recipient.
     *
     * @return int number of entries removed by this call
     */
    public function removeAll(int $recipientId): int;
}
