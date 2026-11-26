<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Consecutive timeline items of one actor that belong together (ADR 025):
 *
 * - `session`: items written in the same authenticated session (same session reference) — evidence.
 * - `inferred`: items without a session reference, at most AuditActivityGrouper::MAX_GAP apart — a guess,
 *   always shown as such.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit timeline template.
 */
final readonly class AuditActivityGroup
{
    public const string SESSION = 'session';

    public const string INFERRED = 'inferred';

    /**
     * @param non-empty-list<AuditTimelineEntry|AuditRequestStack> $items          newest first
     * @param bool                                                 $continuesNewer the group may go on before the first item (previous page)
     * @param bool                                                 $continuesOlder the group may go on after the last item (next page)
     */
    public function __construct(
        public string $kind,
        public ?string $sessionRef,
        public array $items,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public bool $continuesNewer = false,
        public bool $continuesOlder = false,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by the audit timeline template. */
    public function isInferred(): bool
    {
        return self::INFERRED === $this->kind;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by the audit timeline template. */
    public function entryCount(): int
    {
        return array_sum(array_map(
            static fn (AuditTimelineEntry|AuditRequestStack $item): int => $item instanceof AuditRequestStack ? \count($item->entries) : 1,
            $this->items,
        ));
    }
}
