<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeClock;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeHourDraft;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeSeriesScope;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class ClosureVolumeProjectionQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<int> $hospitalIds
     */
    public function hasRows(array $hospitalIds): bool
    {
        if ([] === $hospitalIds) {
            return false;
        }

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM closure_volume_hour WHERE hospital_id IN (:hospital_ids)',
            ['hospital_ids' => $hospitalIds],
            ['hospital_ids' => ArrayParameterType::INTEGER],
        );

        return (int) $count > 0;
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function affectedRows(ClosureAnalyticsCriteria $criteria, ClosureVolumeStratum $stratum): array
    {
        if ([] === ($criteria->scope->hospitalIds ?? [])) {
            return [];
        }
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $params['volume_stratum'] = $stratum->storageStratum();
        $params['volume_scope'] = ClosureVolumeSeriesScope::Department->sqlValue();
        $types['volume_stratum'] = ParameterType::STRING;
        $types['volume_scope'] = ParameterType::STRING;
        $urgencySql = '';
        $urgency = $stratum->urgencyCode();
        if (null !== $urgency) {
            $urgencySql = 'AND h.urgency_code = :volume_urgency';
            $params['volume_urgency'] = $urgency;
            $types['volume_urgency'] = ParameterType::INTEGER;
        }

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT h.hospital_id, h.speciality_id, h.department_id, h.urgency_code, h.stratum,
       h.bucket_start, h.bucket_end, h.observed_area, h.expected_area,
       h.bucket_seconds, h.reference_cutoff
FROM closure_volume_hour h
JOIN (
    SELECT DISTINCT CAST(v.event_key AS integer) AS event_id
    FROM valid_closures v
) matched ON matched.event_id = h.event_id
WHERE h.scope = :volume_scope
  AND h.in_closure = TRUE
  AND h.stratum = :volume_stratum
  {$urgencySql}
SQL, $params, $types);

        return $rows;
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<ClosureVolumeHourDraft>
     */
    public function draftsForEvent(
        int $eventId,
        array $hospitalIds,
        ClosureVolumeStratum $stratum,
        ClosureVolumeSeriesScope $scope = ClosureVolumeSeriesScope::Department,
    ): array {
        if ($eventId <= 0 || [] === $hospitalIds) {
            return [];
        }
        $params = [
            'event_id' => $eventId,
            'hospital_ids' => $hospitalIds,
            'volume_stratum' => $stratum->storageStratum(),
            'volume_scope' => $scope->sqlValue(),
        ];
        $types = [
            'event_id' => ParameterType::INTEGER,
            'hospital_ids' => ArrayParameterType::INTEGER,
            'volume_stratum' => ParameterType::STRING,
            'volume_scope' => ParameterType::STRING,
        ];
        $urgencySql = '';
        $urgency = $stratum->urgencyCode();
        if (null !== $urgency) {
            $urgencySql = 'AND urgency_code = :volume_urgency';
            $params['volume_urgency'] = $urgency;
            $types['volume_urgency'] = ParameterType::INTEGER;
        }

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT event_id, scope, speciality_id, hospital_id, department_id, urgency_code, stratum,
       bucket_start, bucket_end, in_closure, observed_area, expected_area,
       observed_hospital, expected_hospital, evaluable_seconds, bucket_seconds,
       reference_slot_count, reference_assignment_count, reference_mode, influenced,
       quality, reference_cutoff
FROM closure_volume_hour
WHERE event_id = :event_id
  AND scope = :volume_scope
  AND hospital_id IN (:hospital_ids)
  AND stratum = :volume_stratum
  {$urgencySql}
ORDER BY bucket_start ASC, urgency_code ASC, speciality_id ASC, department_id ASC
SQL, $params, $types);

        return array_map($this->draft(...), $rows);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<ClosureVolumeHourDraft>
     */
    public function draftsForEventBarLayers(
        int $eventId,
        array $hospitalIds,
        ClosureVolumeSeriesScope $scope = ClosureVolumeSeriesScope::Department,
    ): array {
        if ($eventId <= 0 || [] === $hospitalIds) {
            return [];
        }

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT event_id, scope, speciality_id, hospital_id, department_id, urgency_code, stratum,
       bucket_start, bucket_end, in_closure, observed_area, expected_area,
       observed_hospital, expected_hospital, evaluable_seconds, bucket_seconds,
       reference_slot_count, reference_assignment_count, reference_mode, influenced,
       quality, reference_cutoff
FROM closure_volume_hour
WHERE event_id = :event_id
  AND scope = :volume_scope
  AND hospital_id IN (:hospital_ids)
  AND stratum IN ('base', 'resus', 'cathlab')
ORDER BY bucket_start ASC, urgency_code ASC, speciality_id ASC, department_id ASC, stratum ASC
SQL, [
            'event_id' => $eventId,
            'hospital_ids' => $hospitalIds,
            'volume_scope' => $scope->sqlValue(),
        ], [
            'event_id' => ParameterType::INTEGER,
            'hospital_ids' => ArrayParameterType::INTEGER,
            'volume_scope' => ParameterType::STRING,
        ]);

        return array_map($this->draft(...), $rows);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<array<string, int|string>>
     */
    public function hospitalHours(array $hospitalIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $hospitalIds) {
            return [];
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT hospital_id,
       urgency_code,
       to_char(date_trunc('hour', created_at), 'YYYY-MM-DD HH24:00:00') AS hour_start,
       COUNT(*)::int AS base_count,
       COUNT(*) FILTER (WHERE requires_resus IS TRUE)::int AS resus_count,
       COUNT(*) FILTER (WHERE requires_cathlab IS TRUE)::int AS cathlab_count
FROM allocation_stats_projection
WHERE hospital_id IN (:hospital_ids)
  AND created_at >= :from
  AND created_at < :to
GROUP BY hospital_id, urgency_code, date_trunc('hour', created_at)
SQL,
            [
                'hospital_ids' => $hospitalIds,
                'from' => ClosureVolumeClock::wall($from)->format('Y-m-d H:i:s'),
                'to' => ClosureVolumeClock::wall($to)->format('Y-m-d H:i:s'),
            ],
            [
                'hospital_ids' => ArrayParameterType::INTEGER,
                'from' => ParameterType::STRING,
                'to' => ParameterType::STRING,
            ],
        );

        return $rows;
    }

    /**
     * @param list<int> $hospitalIds
     */
    public function earliestAllocation(\DateTimeImmutable $before, array $hospitalIds): ?\DateTimeImmutable
    {
        if ([] === $hospitalIds) {
            return null;
        }
        $value = $this->connection->fetchOne(
            'SELECT MIN(created_at) FROM allocation_stats_projection WHERE hospital_id IN (:hospital_ids) AND created_at < :before',
            [
                'hospital_ids' => $hospitalIds,
                'before' => ClosureVolumeClock::wall($before)->format('Y-m-d H:i:s'),
            ],
            [
                'hospital_ids' => ArrayParameterType::INTEGER,
                'before' => ParameterType::STRING,
            ],
        );
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<array{id: int, departmentId: int, departmentName: string, specialityId: int, careLevel: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>
     */
    public function eventMembers(int $eventId, array $hospitalIds): array
    {
        if ($eventId <= 0 || [] === $hospitalIds) {
            return [];
        }
        /** @var list<array<string, int|string>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT ai.id, ai.department_id, d.name AS department_name, ai.speciality_id, cl.care_level, ai.starts_at, ai.ends_at
FROM closure_analysis_interval ai
INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
INNER JOIN department d ON d.id = ai.department_id
WHERE ai.event_id = :event_id
  AND ai.hospital_id IN (:hospital_ids)
ORDER BY d.name ASC, cl.care_level ASC
SQL,
            ['event_id' => $eventId, 'hospital_ids' => $hospitalIds],
            ['event_id' => ParameterType::INTEGER, 'hospital_ids' => ArrayParameterType::INTEGER],
        );
        $bounds = [];
        foreach ($rows as $row) {
            $bounds[] = [
                'id' => (int) $row['id'],
                'departmentId' => (int) $row['department_id'],
                'departmentName' => (string) $row['department_name'],
                'specialityId' => (int) $row['speciality_id'],
                'careLevel' => (string) $row['care_level'],
                'startsAt' => new \DateTimeImmutable((string) $row['starts_at']),
                'endsAt' => new \DateTimeImmutable((string) $row['ends_at']),
            ];
        }

        return $bounds;
    }

    /**
     * @param list<int> $departmentIds
     * @param list<int> $hospitalIds
     *
     * @return list<array{start: string, end: string, label: string}>
     */
    public function otherClosures(
        int $hospitalId,
        array $hospitalIds,
        array $departmentIds,
        int $eventId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        if (!\in_array($hospitalId, $hospitalIds, true) || [] === $departmentIds) {
            return [];
        }
        /** @var list<array<string, int|string|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT d.name AS department_name, ai.starts_at, ai.ends_at
FROM closure_analysis_interval ai
JOIN department d ON d.id = ai.department_id
WHERE ai.hospital_id = :hospital
  AND ai.department_id IN (:department_ids)
  AND ai.ends_at > :from
  AND ai.starts_at < :to
  AND ai.event_id <> :event_id
ORDER BY ai.starts_at ASC, ai.id ASC
SQL,
            [
                'hospital' => $hospitalId,
                'department_ids' => $departmentIds,
                'from' => ClosureVolumeClock::wall($from)->format('Y-m-d H:i:s'),
                'to' => ClosureVolumeClock::wall($to)->format('Y-m-d H:i:s'),
                'event_id' => $eventId,
            ],
            [
                'hospital' => ParameterType::INTEGER,
                'department_ids' => ArrayParameterType::INTEGER,
                'from' => ParameterType::STRING,
                'to' => ParameterType::STRING,
                'event_id' => ParameterType::INTEGER,
            ],
        );

        $others = [];
        foreach ($rows as $row) {
            $start = new \DateTimeImmutable((string) $row['starts_at']);
            $end = new \DateTimeImmutable((string) $row['ends_at']);
            $others[] = [
                'start' => ClosureVolumeClock::wall($start)->format('Y-m-d H:i:s'),
                'end' => ClosureVolumeClock::wall($end)->format('Y-m-d H:i:s'),
                'label' => (string) $row['department_name'],
            ];
        }

        return $others;
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function draft(array $row): ClosureVolumeHourDraft
    {
        return new ClosureVolumeHourDraft(
            (int) $row['event_id'],
            (int) $row['hospital_id'],
            (int) $row['department_id'],
            (int) $row['urgency_code'],
            (string) $row['stratum'],
            ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['bucket_start'])),
            ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['bucket_end'])),
            $this->boolean($row['in_closure']),
            $this->nullableFloat($row['observed_area']),
            $this->nullableFloat($row['expected_area']),
            $this->nullableFloat($row['observed_hospital']),
            $this->nullableFloat($row['expected_hospital']),
            (int) $row['evaluable_seconds'],
            (int) $row['bucket_seconds'],
            (int) $row['reference_slot_count'],
            (int) $row['reference_assignment_count'],
            (string) $row['reference_mode'],
            $this->boolean($row['influenced']),
            (string) $row['quality'],
            ClosureVolumeClock::wall(new \DateTimeImmutable((string) $row['reference_cutoff'])),
            (string) ($row['scope'] ?? 'speciality'),
            (int) ($row['speciality_id'] ?? 0),
        );
    }

    private function boolean(int|string|bool|null $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value || 'true' === $value;
    }

    private function nullableFloat(int|string|null $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }
}
