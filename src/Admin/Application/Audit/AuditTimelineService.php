<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Builds pages of the audit timeline and the full view of one request.
 */
final readonly class AuditTimelineService
{
    public const int PAGE_SIZE = 100;

    /** A request view shows at most this many entries; larger requests say so. */
    public const int REQUEST_LIMIT = 500;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private AuditTimelineReader $reader,
        private AuditTimelineAssembler $assembler,
    ) {
    }

    public function page(AuditTimelineFilter $filter, ?AuditTimelineCursor $after, int $size = self::PAGE_SIZE): AuditTimelinePage
    {
        ['entries' => $entries, 'hasMore' => $hasMore] = $this->reader->page($filter, $after, $size);
        if ([] === $entries) {
            return new AuditTimelinePage([], null);
        }

        $requestIds = array_values(array_unique(array_map(static fn (AuditTimelineEntry $e): string => $e->requestId, $entries)));
        $stats = $this->reader->requestStats($requestIds, $filter);

        return new AuditTimelinePage(
            $this->assembler->assemble($entries, $stats),
            $hasMore ? AuditTimelineCursor::after($entries[\count($entries) - 1]) : null,
        );
    }

    /**
     * All entries of one request (up to REQUEST_LIMIT), with the facts about the whole request.
     *
     * With a filter, `matchingIds` holds the ids of the entries that match it; every other entry is context.
     * Without a filter it is null: every entry matches.
     *
     * @return array{entries: list<AuditTimelineEntry>, stats: AuditRequestStats|null, matchingIds: array<int, true>|null}
     */
    public function request(string $requestId, AuditTimelineFilter $filter): array
    {
        $matchingIds = null;
        if (!$filter->isEmpty()) {
            $matching = $this->reader->page($filter->withRequestId($requestId), null, self::REQUEST_LIMIT)['entries'];
            $matchingIds = array_fill_keys(array_map(static fn (AuditTimelineEntry $e): int => $e->id, $matching), true);
        }

        return [
            'entries' => $this->reader->request($requestId, self::REQUEST_LIMIT),
            'stats' => $this->reader->requestStats([$requestId], $filter)[$requestId] ?? null,
            'matchingIds' => $matchingIds,
        ];
    }

    public function details(int $id): ?AuditEntryDetails
    {
        return $this->reader->details($id);
    }

    public function filterOptions(): AuditFilterOptions
    {
        return $this->reader->filterOptions();
    }
}
