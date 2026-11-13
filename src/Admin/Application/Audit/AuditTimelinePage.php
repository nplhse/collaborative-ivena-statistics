<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * One page of the audit timeline, newest first: single entries and request stacks.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit timeline template.
 */
final readonly class AuditTimelinePage
{
    /**
     * @param list<AuditTimelineEntry|AuditRequestStack> $items
     * @param list<AuditActivityGroup>|null              $groups the same items grouped by session or inferred activity
     *                                                           period; only when the page is filtered to one actor
     */
    public function __construct(
        public array $items,
        public ?AuditTimelineCursor $next,
        public ?array $groups = null,
    ) {
    }

    /**
     * Position of the newest entry on the page, the reference for "new entries" since it was loaded.
     *
     * @psalm-suppress PossiblyUnusedMethod Called by AuditTimelineController.
     */
    public function newest(): ?AuditTimelineCursor
    {
        $first = $this->items[0] ?? null;
        if (null === $first) {
            return null;
        }

        if ($first instanceof AuditTimelineEntry) {
            return AuditTimelineCursor::after($first);
        }

        $newest = $first->entries[\count($first->entries) - 1];

        return AuditTimelineCursor::after($newest);
    }
}
