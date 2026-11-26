<?php

declare(strict_types=1);

namespace App\Admin\Application\Audit;

/**
 * Groups the items of a page filtered to one actor into sessions and inferred activity periods (ADR 025).
 *
 * Walking from newest to oldest:
 * - An item with a session reference joins the previous group when that group is the same session,
 *   otherwise it starts a new session group.
 * - An item without one joins the previous group when that group is inferred and the gap between the two
 *   items is at most MAX_GAP; otherwise it starts a new inferred group.
 * - A stack counts with its first entry's session reference and the time span of its entries.
 *
 * Groups at the page edges may continue on the neighbouring page and say so. Nothing is stored.
 */
final class AuditActivityGrouper
{
    /** Longest pause inside one inferred activity period, in seconds. */
    public const int MAX_GAP = 30 * 60;

    /**
     * @param list<AuditTimelineEntry|AuditRequestStack> $items newest first
     *
     * @return list<AuditActivityGroup>
     */
    public function group(array $items, bool $hasNewerPage, bool $hasOlderPage): array
    {
        /** @var list<array{kind: string, ref: ?string, items: non-empty-list<AuditTimelineEntry|AuditRequestStack>, start: \DateTimeImmutable, end: \DateTimeImmutable}> $groups */
        $groups = [];

        foreach ($items as $item) {
            [$start, $end] = $this->span($item);
            $ref = $item instanceof AuditRequestStack ? $item->first()->sessionRef : $item->sessionRef;
            $kind = null === $ref ? AuditActivityGroup::INFERRED : AuditActivityGroup::SESSION;
            $last = array_key_last($groups);

            if (null !== $last && $this->continues($groups[$last], $kind, $ref, $end)) {
                $groups[$last]['items'][] = $item;
                $groups[$last]['start'] = min($groups[$last]['start'], $start);

                continue;
            }

            $groups[] = ['kind' => $kind, 'ref' => $ref, 'items' => [$item], 'start' => $start, 'end' => $end];
        }

        $lastIndex = \count($groups) - 1;

        return array_map(
            static fn (int $i, array $g): AuditActivityGroup => new AuditActivityGroup(
                $g['kind'],
                $g['ref'],
                $g['items'],
                $g['start'],
                $g['end'],
                0 === $i && $hasNewerPage,
                $i === $lastIndex && $hasOlderPage,
            ),
            array_keys($groups),
            $groups,
        );
    }

    /**
     * @param array{kind: string, ref: ?string, items: non-empty-list<AuditTimelineEntry|AuditRequestStack>, start: \DateTimeImmutable, end: \DateTimeImmutable} $group
     */
    private function continues(array $group, string $kind, ?string $ref, \DateTimeImmutable $itemEnd): bool
    {
        if ($group['kind'] !== $kind) {
            return false;
        }

        if (AuditActivityGroup::SESSION === $kind) {
            return $group['ref'] === $ref;
        }

        return $group['start']->getTimestamp() - $itemEnd->getTimestamp() <= self::MAX_GAP;
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} oldest and newest time of the item
     */
    private function span(AuditTimelineEntry|AuditRequestStack $item): array
    {
        if ($item instanceof AuditTimelineEntry) {
            return [$item->occurredAt, $item->occurredAt];
        }

        return [$item->first()->occurredAt, $item->entries[\count($item->entries) - 1]->occurredAt];
    }
}
