<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureBreakdownRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureUnitTableRow;

final class ClosureUnitTableRows
{
    /**
     * @param list<ClosureBreakdownRow> $rows
     *
     * @return list<ClosureUnitTableRow>
     */
    public static function sorted(array $rows, int $totalClosedMinutes, string $sortBy, string $orderBy): array
    {
        $totalClosedMinutes = max(0, $totalClosedMinutes);
        $mapped = array_map(
            static fn (ClosureBreakdownRow $row): ClosureUnitTableRow => new ClosureUnitTableRow(
                $row->key,
                $row->hospitalName ?? '',
                $row->name,
                $row->eventCount ?? $row->closureCount,
                $row->actualMinutes,
                $totalClosedMinutes > 0 ? (float) $row->actualMinutes * 100.0 / (float) $totalClosedMinutes : 0.0,
                $row->hospitalId,
            ),
            $rows,
        );
        $direction = 'asc' === $orderBy ? 1 : -1;
        usort(
            $mapped,
            static function (ClosureUnitTableRow $left, ClosureUnitTableRow $right) use ($sortBy, $direction): int {
                $primary = match ($sortBy) {
                    'hospital' => strnatcasecmp($left->hospitalName, $right->hospitalName),
                    'name' => strnatcasecmp($left->name, $right->name),
                    'eventCount' => $left->eventCount <=> $right->eventCount,
                    default => $left->actualMinutes <=> $right->actualMinutes,
                };
                if (0 !== $primary) {
                    return $primary * $direction;
                }

                $name = strnatcasecmp($left->name, $right->name);
                if (0 !== $name) {
                    return $name;
                }

                return strnatcasecmp($left->hospitalName, $right->hospitalName);
            },
        );

        return $mapped;
    }
}
