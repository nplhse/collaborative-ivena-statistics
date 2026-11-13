<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Folds the entries of one timeline page into single entries and request stacks (ADR 025).
 *
 * - Entries are stacked only by request id, never by time.
 * - A legacy request id (written before ADR 025, possibly sent by a client) is stacked only when all its
 *   entries share actor and origin; otherwise its entries stay single.
 * - A stack takes the position of its newest entry on the page; inside, entries run oldest first.
 * - An entry stays single when it is the only entry of its request.
 */
final class AuditTimelineAssembler
{
    /**
     * @param list<AuditTimelineEntry>         $entries newest first, as the page query returns them
     * @param array<string, AuditRequestStats> $stats   per request id of the entries
     *
     * @return list<AuditTimelineEntry|AuditRequestStack>
     */
    public function assemble(array $entries, array $stats): array
    {
        /** @var array<string, non-empty-list<AuditTimelineEntry>> $members */
        $members = [];
        foreach ($entries as $entry) {
            if ($this->isStackable($entry, $stats[$entry->requestId] ?? null)) {
                $members[$entry->requestId][] = $entry;
            }
        }

        $items = [];
        $placed = [];
        foreach ($entries as $entry) {
            $requestStats = $stats[$entry->requestId] ?? null;
            if (!isset($members[$entry->requestId]) || !$requestStats instanceof AuditRequestStats) {
                $items[] = $entry;

                continue;
            }

            if (isset($placed[$entry->requestId])) {
                continue;
            }

            $placed[$entry->requestId] = true;
            $stackEntries = $members[$entry->requestId];
            usort($stackEntries, AuditTimelineEntry::compareAscending(...));
            $items[] = new AuditRequestStack($entry->requestId, $stackEntries, $requestStats);
        }

        return $items;
    }

    private function isStackable(AuditTimelineEntry $entry, ?AuditRequestStats $stats): bool
    {
        if (!$stats instanceof AuditRequestStats || $stats->total < 2) {
            return false;
        }

        return !$entry->legacyRequestId || (1 === $stats->distinctActors && 1 === $stats->distinctOrigins);
    }
}
