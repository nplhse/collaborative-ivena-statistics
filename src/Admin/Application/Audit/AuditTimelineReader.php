<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Read port of the audit timeline (ADR 009); implemented by AuditTimelineQuery.
 */
interface AuditTimelineReader
{
    /**
     * Entries matching the filter, newest first, after the cursor; up to $size entries and whether more follow.
     *
     * @return array{entries: list<AuditTimelineEntry>, hasMore: bool}
     */
    public function page(AuditTimelineFilter $filter, ?AuditTimelineCursor $after, int $size): array;

    /**
     * @param list<string> $requestIds
     *
     * @return array<string, AuditRequestStats>
     */
    public function requestStats(array $requestIds, AuditTimelineFilter $filter): array;

    /**
     * Entries of one request, oldest first, at most $limit.
     *
     * @return list<AuditTimelineEntry>
     */
    public function request(string $requestId, int $limit): array;

    /**
     * One entry with its recorded changes and metadata, or null when it does not exist.
     */
    public function details(int $id): ?AuditEntryDetails;
}
