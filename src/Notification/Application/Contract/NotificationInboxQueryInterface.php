<?php

declare(strict_types=1);

namespace App\Notification\Application\Contract;

use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Application\Inbox\InboxPage;
use App\Notification\Application\Inbox\RecipientInboxState;

/**
 * Reads one recipient's inbox: visible, not removed entries, newest first.
 */
interface NotificationInboxQueryInterface
{
    public function state(int $recipientId): RecipientInboxState;

    /**
     * The newest unread entries, e.g. for the header dropdown. Fewer than $limit when fewer are unread.
     *
     * @return list<InboxEntry>
     */
    public function latestUnread(int $recipientId, int $limit = InboxPage::DROPDOWN_SIZE): array;

    /**
     * Number of entries in the inbox (read and unread, not removed).
     */
    public function count(int $recipientId): int;

    /**
     * One page of up to $size entries, newest first. Without cursor it starts with the newest entries;
     * with $before it continues with older entries, with $after with newer ones ($before wins if both are set).
     *
     * @param bool $unreadOnly only entries that have not been read yet
     */
    public function page(
        int $recipientId,
        ?InboxCursor $before = null,
        ?InboxCursor $after = null,
        int $size = InboxPage::OVERVIEW_PAGE_SIZE,
        bool $unreadOnly = false,
    ): InboxPage;

    public function find(int $recipientId, int $notificationId): ?InboxEntry;
}
