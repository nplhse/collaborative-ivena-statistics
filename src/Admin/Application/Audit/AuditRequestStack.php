<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Entries of one request on the current page, oldest first.
 *
 * A stack is complete only when every entry of the request is on the page. Entries of background jobs the
 * request started (origin messenger) are kept apart from the request's own entries.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the audit timeline templates.
 */
final readonly class AuditRequestStack
{
    /**
     * @param non-empty-list<AuditTimelineEntry> $entries
     */
    public function __construct(
        public string $requestId,
        public array $entries,
        public AuditRequestStats $stats,
    ) {
    }

    public function first(): AuditTimelineEntry
    {
        return $this->entries[0];
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by the audit timeline templates. */
    public function isComplete(): bool
    {
        return \count($this->entries) >= $this->stats->total;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by the audit timeline templates. */
    public function isLegacy(): bool
    {
        return $this->first()->legacyRequestId;
    }

    /**
     * Entries other than those of background jobs. When the page holds only job entries, those are the request.
     *
     * @return list<AuditTimelineEntry>
     */
    public function ownEntries(): array
    {
        $own = array_values(array_filter($this->entries, static fn (AuditTimelineEntry $e): bool => 'messenger' !== $e->origin));

        return [] === $own ? $this->entries : $own;
    }

    /**
     * @return list<AuditTimelineEntry>
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the audit timeline templates.
     */
    public function backgroundEntries(): array
    {
        if (\count($this->ownEntries()) === \count($this->entries)) {
            return [];
        }

        return array_values(array_filter($this->entries, static fn (AuditTimelineEntry $e): bool => 'messenger' === $e->origin));
    }

    /**
     * Names of the affected object types on this page, most frequent first, at most $limit.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the audit timeline templates.
     *
     * @return list<string>
     */
    public function entityNames(int $limit = 3): array
    {
        $counts = array_count_values(array_map(static fn (AuditTimelineEntry $e): string => $e->entityName(), $this->entries));
        arsort($counts);

        return \array_slice(array_keys($counts), 0, $limit);
    }

    /**
     * The intent that names the request: the first entry with one, else null.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the audit timeline templates.
     */
    public function intent(): ?string
    {
        foreach ($this->entries as $entry) {
            if (null !== $entry->intent) {
                return $entry->intent;
            }
        }

        return null;
    }
}
