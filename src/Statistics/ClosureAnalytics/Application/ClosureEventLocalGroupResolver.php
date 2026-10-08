<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventLocalGroupRef;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ClosureEventLocalGroupResolver
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<ClosureIntervalRow> $members
     *
     * @return list<ClosureEventLocalGroupRef>
     */
    public function resolve(int $hospitalId, string $currentEventKey, array $members): array
    {
        /** @var array<string, array{count: int, units: array<string, true>}> $grouped */
        $grouped = [];
        foreach ($members as $member) {
            $sourceGroupId = null === $member->sourceGroupId ? '' : trim($member->sourceGroupId);
            if ('' === $sourceGroupId) {
                continue;
            }

            $grouped[$sourceGroupId] ??= ['count' => 0, 'units' => []];
            ++$grouped[$sourceGroupId]['count'];
            $unit = null === $member->closureUnit ? '' : trim($member->closureUnit);
            if ('' !== $unit) {
                $grouped[$sourceGroupId]['units'][$unit] = true;
            }
        }

        if ([] === $grouped) {
            return [];
        }

        $eventKeys = $this->eventKeysForSourceGroups($hospitalId, array_keys($grouped));
        $refs = [];
        foreach ($grouped as $sourceGroupId => $data) {
            $units = array_keys($data['units']);
            sort($units, SORT_NATURAL | SORT_FLAG_CASE);
            $label = 1 === \count($units) ? $units[0] : $sourceGroupId;
            $eventKey = $eventKeys[$sourceGroupId] ?? null;
            $refs[] = new ClosureEventLocalGroupRef(
                $sourceGroupId,
                $label,
                $eventKey,
                $data['count'],
                $units,
                null !== $eventKey && $eventKey === $currentEventKey,
            );
        }

        usort(
            $refs,
            static fn (ClosureEventLocalGroupRef $left, ClosureEventLocalGroupRef $right): int => [$left->label, $left->sourceGroupId]
                <=> [$right->label, $right->sourceGroupId],
        );

        return $refs;
    }

    /**
     * @param list<string> $sourceGroupIds
     *
     * @return array<string, string>
     */
    private function eventKeysForSourceGroups(int $hospitalId, array $sourceGroupIds): array
    {
        if ([] === $sourceGroupIds) {
            return [];
        }

        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
SELECT source_group_id, id::text
FROM closure_event
WHERE hospital_id = :hospital
  AND event_type = :event_type
  AND source_group_id IN (:source_group_ids)
SQL,
            [
                'hospital' => $hospitalId,
                'event_type' => ClosureEventType::Group->value,
                'source_group_ids' => $sourceGroupIds,
            ],
            [
                'hospital' => ParameterType::INTEGER,
                'event_type' => ParameterType::STRING,
                'source_group_ids' => ArrayParameterType::STRING,
            ],
        );

        return $rows;
    }
}
