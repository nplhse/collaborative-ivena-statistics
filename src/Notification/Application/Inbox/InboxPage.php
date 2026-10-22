<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

final readonly class InboxPage
{
    public const int OVERVIEW_PAGE_SIZE = 25;

    public const int DROPDOWN_SIZE = 5;

    /**
     * @param list<InboxEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?InboxCursor $nextCursor,
    ) {
    }
}
