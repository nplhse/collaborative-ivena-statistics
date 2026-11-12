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
     */
    public function __construct(
        public array $items,
        public ?AuditTimelineCursor $next,
    ) {
    }
}
