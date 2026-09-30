<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ClosureIntervalDetailQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function fetch(ClosureAnalyticsCriteria $criteria, int $id): ?ClosureIntervalRow
    {
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        /** @var array<string, int|string|null>|false $row */
        $row = $this->connection->fetchAssociative(<<<SQL
WITH {$base}
SELECT v.id, v.hospital_id, h.name AS hospital_name, s.name AS speciality_name, d.name AS department_name,
       v.starts_utc AS starts_at, v.ends_utc AS ends_at, v.care_level, v.reason,
       v.closure_unit, NULLIF(BTRIM(v.source_group_id), '') AS source_group_id,
       v.facility_kind, v.source_recorded_at, v.source_changed_at,
       ROUND(EXTRACT(EPOCH FROM (v.ends_utc - v.starts_utc)) / 60.0)::int AS duration_minutes,
       v.event_key, v.event_type, v.department_id
FROM valid_closures v
JOIN hospital h ON h.id = v.hospital_id
JOIN speciality s ON s.id = v.speciality_id
JOIN department d ON d.id = v.department_id
WHERE v.id = :id
SQL, [...$params, 'id' => $id], [...$types, 'id' => ParameterType::INTEGER]);
        if (false === $row) {
            return null;
        }

        return new ClosureIntervalRow(
            (int) $row['id'],
            (int) $row['hospital_id'],
            (string) $row['hospital_name'],
            (string) $row['speciality_name'],
            (string) $row['department_name'],
            new \DateTimeImmutable((string) $row['starts_at']),
            new \DateTimeImmutable((string) $row['ends_at']),
            (string) $row['care_level'],
            (string) $row['reason'],
            null === $row['closure_unit'] ? null : (string) $row['closure_unit'],
            null === $row['source_group_id'] ? null : (string) $row['source_group_id'],
            (int) $row['duration_minutes'],
            (string) $row['event_key'],
            ClosureEventType::from((string) $row['event_type']),
            (int) $row['department_id'],
            null === $row['facility_kind'] ? null : (string) $row['facility_kind'],
            null === $row['source_recorded_at'] ? null : new \DateTimeImmutable((string) $row['source_recorded_at']),
            null === $row['source_changed_at'] ? null : new \DateTimeImmutable((string) $row['source_changed_at']),
        );
    }
}
