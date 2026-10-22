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
     * @return list<InboxEntry>
     */
    public function latest(int $recipientId, int $limit = InboxPage::DROPDOWN_SIZE): array;

    public function page(int $recipientId, ?InboxCursor $cursor, int $size = InboxPage::OVERVIEW_PAGE_SIZE): InboxPage;

    public function find(int $recipientId, int $notificationId): ?InboxEntry;
}
