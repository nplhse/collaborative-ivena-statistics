<?php

declare(strict_types=1);

namespace App\Notification\Application\Inbox;

/**
 * One page of the inbox, newest first, with keyset cursors to the neighbouring pages.
 */
final readonly class InboxPage
{
    public const int OVERVIEW_PAGE_SIZE = 25;

    public const int DROPDOWN_SIZE = 5;

    /**
     * @param list<InboxEntry> $entries
     * @param InboxCursor|null $olderCursor position to continue with older entries ("before" this cursor)
     * @param InboxCursor|null $newerCursor position to continue with newer entries ("after" this cursor)
     */
    public function __construct(
        public array $entries,
        public ?InboxCursor $olderCursor = null,
        public ?InboxCursor $newerCursor = null,
    ) {
    }
}
