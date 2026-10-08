<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsCriteria;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureProfilePhaseStratumBreakdown;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCareCount;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCoursePoint;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCourseSeries;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileCoverage;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileDepartment;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileEventLinks;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileGroupUnitRedundancy;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileKind;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileListItem;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileMember;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileOverview;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfilePackedMembersParser;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfilePhaseTotals;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileRef;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileSpecialityLink;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileView;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureRecurringProfilePage;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureRecurringProfileRow;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureRecurringProfileTableQuery;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosureProfileQuery
{
    private const int QUARTILE_MINIMUM = 5;
    private const int OVERVIEW_LIMIT = 8;
    private const int COVERAGE_YEAR_ORIGIN = 2015;
    private const int COVERAGE_YEAR_COLUMNS = 5;

    public function __construct(
        private Connection $connection,
        private ClosureVolumeReferenceConfig $referenceConfig,
        private TranslatorInterface $translator,
        private ClosureProfileGroupUnitRedundancy $groupUnitRedundancy,
        private ClosureProfilePackedMembersParser $packedMembersParser,
    ) {
    }

    public function overview(ClosureAnalyticsCriteria $criteria): ClosureProfileOverview
    {
        if ([] === ($criteria->scope->hospitalIds ?? [])) {
            return new ClosureProfileOverview([], []);
        }

        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        $groups = $this->nonRedundantGroupItems($criteria, $base, $params, $types, self::OVERVIEW_LIMIT);

        $specialityRows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT v.hospital_id,
       h.name AS hospital_name,
       v.speciality_id,
       s.name AS title,
       COUNT(DISTINCT v.event_key)::int AS event_count
FROM valid_closures v
INNER JOIN hospital h ON h.id = v.hospital_id
INNER JOIN speciality s ON s.id = v.speciality_id
GROUP BY v.hospital_id, h.name, v.speciality_id, s.name
ORDER BY COUNT(DISTINCT v.event_key) DESC, s.name ASC, h.name ASC
LIMIT {$this->limit()}
SQL, $params, $types);

        $specialities = array_map($this->specialityItem(...), $specialityRows);
        if (\count($specialities) < self::OVERVIEW_LIMIT) {
            $specialities = $this->appendAssignmentSpecialities($criteria, $params, $types, $specialities);
        }

        return new ClosureProfileOverview(
            $groups,
            $specialities,
        );
    }

    /**
     * @return list<ClosureProfileListItem>
     */
    public function recurringGroups(ClosureAnalyticsCriteria $criteria): array
    {
        if ([] === ($criteria->scope->hospitalIds ?? [])) {
            return [];
        }

        [$base, $params, $types] = ClosureTemporalSql::base($criteria);

        return $this->nonRedundantGroupItems($criteria, $base, $params, $types, null);
    }

    public function recurringGroupPage(
        ClosureAnalyticsCriteria $criteria,
        ClosureRecurringProfileTableQuery $tableQuery,
    ): ClosureRecurringProfilePage {
        if ([] === ($criteria->scope->hospitalIds ?? [])) {
            return new ClosureRecurringProfilePage([], 0);
        }

        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $redundant = $this->groupUnitRedundancy->redundantGroupKeys($criteria);

        $search = trim($tableQuery->profileQ);
        if ('' !== $search) {
            $params['profile_q'] = '%'.$search.'%';
            $types['profile_q'] = Types::STRING;
            $searchSql = 'WHERE g.hospital_name ILIKE :profile_q OR g.members ILIKE :profile_q';
        } else {
            $searchSql = '';
        }

        $groupedSql = $this->recurringGroupsGroupedSql($base);
        $groupRows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$groupedSql}
SELECT g.hospital_id,
       g.hospital_name,
       g.profile_key,
       g.members,
       g.event_count
FROM grouped g
{$searchSql}
SQL, $params, $types);

        $rows = [];
        foreach ($groupRows as $row) {
            $hospitalId = (int) $row['hospital_id'];
            $profileKey = (string) $row['profile_key'];
            if (isset($redundant[$hospitalId.':'.$profileKey])) {
                continue;
            }
            $rows[] = $this->recurringProfileRow($row);
        }

        usort($rows, fn (ClosureRecurringProfileRow $left, ClosureRecurringProfileRow $right): int => $this->compareRecurringProfileRows(
            $left,
            $right,
            $tableQuery->sortBy,
            $tableQuery->orderBy,
        ));

        $total = \count($rows);
        $offset = $tableQuery->offset();
        $limit = max(1, min(100, $tableQuery->limit));

        return new ClosureRecurringProfilePage(
            \array_slice($rows, $offset, $limit),
            $total,
        );
    }

    private function compareRecurringProfileRows(
        ClosureRecurringProfileRow $left,
        ClosureRecurringProfileRow $right,
        string $sortBy,
        string $orderBy,
    ): int {
        $direction = 'asc' === strtolower($orderBy) ? 1 : -1;
        $compare = match ($sortBy) {
            'hospital' => [$left->hospitalName, $right->hospitalName],
            'configuration' => [implode(', ', $left->specialities), implode(', ', $right->specialities)],
            default => [$left->eventCount, $right->eventCount],
        };

        if ($compare[0] === $compare[1]) {
            return $left->hospitalName <=> $right->hospitalName ?: $left->profileKey <=> $right->profileKey;
        }

        return ($compare[0] <=> $compare[1]) * $direction;
    }

    public function view(ClosureAnalyticsCriteria $criteria, ClosureVolumeStratum $stratum): ?ClosureProfileView
    {
        $profile = $criteria->profile;
        if (!$profile instanceof ClosureProfileRef || !$this->exists($profile, $criteria->scope->hospitalIds ?? [])) {
            return null;
        }

        $hospital = ['name' => '', 'public_id' => ''];
        if ($profile->hospitalId > 0) {
            $loaded = $this->hospital($profile->hospitalId);
            if (null === $loaded) {
                return null;
            }
            $hospital = $loaded;
        }

        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $duration = $this->duration($base, $params, $types, $profile);
        $members = ClosureProfileKind::Group === $profile->kind
            ? $this->groupMembers($profile)
            : $this->specialityMembers($base, $params, $types);
        $title = match ($profile->kind) {
            ClosureProfileKind::Speciality => $this->specialityName($profile->specialityId()),
            ClosureProfileKind::Group => $this->groupTitle($members),
            ClosureProfileKind::Hospital => $hospital['name'],
            ClosureProfileKind::Department => $this->departmentName((int) $profile->key),
            ClosureProfileKind::ClosureUnit => $profile->key,
        };
        $careLevels = $this->careLevels($base, $params, $types);
        $departments = $this->departments($profile, $base, $params, $types, $members);

        return $this->assemble(
            $profile,
            $title,
            $hospital,
            $duration,
            $careLevels,
            $members,
            $departments,
            $this->coverage($base, $params, $types),
            $this->course($criteria, $stratum, false),
            $this->course($criteria, $stratum, true),
            $this->phase($criteria, $stratum),
            $this->heatmap($base, $params, $types),
        );
    }

    /**
     * @return list<ClosureProfilePhaseStratumBreakdown>
     */
    public function assignmentPhaseBreakdown(ClosureAnalyticsCriteria $criteria): array
    {
        $profile = $criteria->profile;
        if (!$profile instanceof ClosureProfileRef) {
            return [];
        }

        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $hours = $this->contextHours();
        $populationSql = $this->assignmentPopulationSql($profile);
        $candidatePopulationSql = $this->assignmentCandidatePopulationSql($profile);
        $this->bindProfileAssignmentParams($profile, $params, $types);

        $perEventSelects = [];
        foreach (ClosureVolumeStratum::choices() as $stratum) {
            $filter = $this->allocationStratumSql('a', $stratum);
            $prefix = $stratum->value;
            $perEventSelects[] = sprintf(
                'COUNT(DISTINCT a.id) FILTER (WHERE %s AND %s) AS %s_before',
                $this->eventPhaseSql('before', $hours),
                $filter,
                $prefix,
            );
            $perEventSelects[] = sprintf(
                'COUNT(DISTINCT a.id) FILTER (WHERE %s AND %s) AS %s_during',
                $this->eventPhaseSql('during', $hours),
                $filter,
                $prefix,
            );
            $perEventSelects[] = sprintf(
                'COUNT(DISTINCT a.id) FILTER (WHERE %s AND %s) AS %s_after',
                $this->eventPhaseSql('after', $hours),
                $filter,
                $prefix,
            );
        }

        $aggregateSelects = [];
        foreach (ClosureVolumeStratum::choices() as $stratum) {
            $prefix = $stratum->value;
            foreach (['before', 'during', 'after'] as $phase) {
                $aggregateSelects[] = sprintf('SUM(%s_%s)::int AS %s_%s_total', $prefix, $phase, $prefix, $phase);
                $aggregateSelects[] = sprintf('AVG(%s_%s)::float AS %s_%s_mean', $prefix, $phase, $prefix, $phase);
            }
        }
        $aggregateSelects[] = 'COUNT(*)::int AS evaluable_events';

        /** @var array<string, int|string|float|null>|false $row */
        $row = $this->connection->fetchAssociative(<<<SQL
WITH {$base},
profile_events AS (
    SELECT DISTINCT CAST(v.event_key AS bigint) AS event_id,
           v.hospital_id,
           e.starts_at,
           e.ends_at
    FROM valid_closures v
    INNER JOIN closure_event e ON e.id = CAST(v.event_key AS bigint)
),
window_bounds AS (
    SELECT MIN(starts_at) - ({$hours} * INTERVAL '1 hour') AS min_created,
           MAX(ends_at) + ({$hours} * INTERVAL '1 hour') AS max_created
    FROM profile_events
),
candidates AS (
    SELECT a.id,
           a.hospital_id,
           a.speciality_id,
           a.department_id,
           a.urgency,
           a.requires_resus,
           a.requires_cathlab,
           a.created_at
    FROM allocation a
    INNER JOIN window_bounds wb ON TRUE
    WHERE a.hospital_id IN (SELECT DISTINCT hospital_id FROM profile_events)
      AND (a.created_at AT TIME ZONE 'Europe/Berlin') >= wb.min_created
      AND (a.created_at AT TIME ZONE 'Europe/Berlin') < wb.max_created
      {$candidatePopulationSql}
),
per_event AS (
    SELECT pe.event_id,
           {$this->sqlJoin($perEventSelects)}
    FROM profile_events pe
    INNER JOIN candidates a ON a.hospital_id = pe.hospital_id
       AND (a.created_at AT TIME ZONE 'Europe/Berlin') >= pe.starts_at - ({$hours} * INTERVAL '1 hour')
       AND (a.created_at AT TIME ZONE 'Europe/Berlin') < pe.ends_at + ({$hours} * INTERVAL '1 hour')
       {$populationSql}
    GROUP BY pe.event_id
)
SELECT
    {$this->sqlJoin($aggregateSelects)}
FROM per_event
SQL, $params, $types);

        if (false === $row) {
            return [];
        }

        $evaluable = (int) ($row['evaluable_events'] ?? 0);
        $breakdown = [];
        foreach (ClosureVolumeStratum::choices() as $stratum) {
            $prefix = $stratum->value;
            $breakdown[] = new ClosureProfilePhaseStratumBreakdown(
                $stratum,
                $this->nullableInt($row[$prefix.'_before_total'] ?? null),
                $this->nullableFloat($row[$prefix.'_before_mean'] ?? null),
                $this->nullableInt($row[$prefix.'_during_total'] ?? null),
                $this->nullableFloat($row[$prefix.'_during_mean'] ?? null),
                $this->nullableInt($row[$prefix.'_after_total'] ?? null),
                $this->nullableFloat($row[$prefix.'_after_mean'] ?? null),
                $evaluable,
            );
        }

        return $breakdown;
    }

    /**
     * @return array<string, list<int|float|null>>
     */
    public function courseBarSeries(ClosureAnalyticsCriteria $criteria, bool $alignToEnd, int $seriesId): array
    {
        $profile = $criteria->profile;
        if (!$profile instanceof ClosureProfileRef) {
            return [];
        }

        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $anchor = $alignToEnd ? 'pe.ends_at' : 'pe.starts_at';
        $hours = $this->contextHours();
        $populationSql = $this->volumeHourPopulationSql($profile);
        $this->bindProfileAssignmentParams($profile, $params, $types);
        $seriesSql = $seriesId > 0 ? 'AND h.speciality_id = :course_series_id' : '';
        if ($seriesId > 0) {
            $params['course_series_id'] = $seriesId;
            $types['course_series_id'] = ParameterType::INTEGER;
        }

        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
profile_events AS (
    SELECT DISTINCT CAST(v.event_key AS bigint) AS event_id,
           v.hospital_id,
           e.starts_at,
           e.ends_at
    FROM valid_closures v
    INNER JOIN closure_event e ON e.id = CAST(v.event_key AS bigint)
),
offsets AS (
    SELECT gs AS hour_offset FROM generate_series(-{$hours}, {$hours} - 1) AS gs
),
bins AS (
    SELECT pe.event_id,
           o.hour_offset,
           {$anchor} + (o.hour_offset * INTERVAL '1 hour') AS bin_start,
           {$anchor} + ((o.hour_offset + 1) * INTERVAL '1 hour') AS bin_end
    FROM profile_events pe
    CROSS JOIN offsets o
),
per_event_bucket AS (
    SELECT b.hour_offset,
           b.event_id,
           COALESCE(SUM(
               CASE WHEN h.stratum = 'base' AND h.urgency_code = 1
                   THEN h.observed_area * EXTRACT(EPOCH FROM (LEAST(h.bucket_end, b.bin_end) - GREATEST(h.bucket_start, b.bin_start))) / NULLIF(h.bucket_seconds, 0)
               END
           ), 0)::int AS sk1,
           COALESCE(SUM(
               CASE WHEN h.stratum = 'base' AND h.urgency_code = 2
                   THEN h.observed_area * EXTRACT(EPOCH FROM (LEAST(h.bucket_end, b.bin_end) - GREATEST(h.bucket_start, b.bin_start))) / NULLIF(h.bucket_seconds, 0)
               END
           ), 0)::int AS sk2,
           COALESCE(SUM(
               CASE WHEN h.stratum = 'base' AND h.urgency_code = 3
                   THEN h.observed_area * EXTRACT(EPOCH FROM (LEAST(h.bucket_end, b.bin_end) - GREATEST(h.bucket_start, b.bin_start))) / NULLIF(h.bucket_seconds, 0)
               END
           ), 0)::int AS sk3,
           COALESCE(SUM(
               CASE WHEN h.stratum = 'resus'
                   THEN h.observed_area * EXTRACT(EPOCH FROM (LEAST(h.bucket_end, b.bin_end) - GREATEST(h.bucket_start, b.bin_start))) / NULLIF(h.bucket_seconds, 0)
               END
           ), 0)::int AS resus,
           COALESCE(SUM(
               CASE WHEN h.stratum = 'cathlab'
                   THEN h.observed_area * EXTRACT(EPOCH FROM (LEAST(h.bucket_end, b.bin_end) - GREATEST(h.bucket_start, b.bin_start))) / NULLIF(h.bucket_seconds, 0)
               END
           ), 0)::int AS cathlab
    FROM bins b
    INNER JOIN closure_volume_hour h
        ON h.event_id = b.event_id
       AND h.scope = 'speciality'
       AND h.stratum IN ('base', 'resus', 'cathlab')
       AND h.bucket_start < b.bin_end
       AND h.bucket_end > b.bin_start
       AND h.observed_area IS NOT NULL
       AND h.bucket_seconds > 0
       {$populationSql}
       {$seriesSql}
    GROUP BY b.hour_offset, b.event_id
)
SELECT hour_offset,
       SUM(sk1)::int AS sk1,
       SUM(sk2)::int AS sk2,
       SUM(sk3)::int AS sk3,
       SUM(resus)::int AS resus,
       SUM(cathlab)::int AS cathlab
FROM per_event_bucket
GROUP BY hour_offset
ORDER BY hour_offset ASC
SQL, $params, $types);

        $byOffset = [];
        foreach ($rows as $row) {
            $byOffset[(int) $row['hour_offset']] = $row;
        }

        $series = [];
        foreach (['sk1', 'sk2', 'sk3', 'resus', 'cathlab'] as $key) {
            $values = [];
            for ($offset = -$hours; $offset < $hours; ++$offset) {
                $row = $byOffset[$offset] ?? null;
                $values[] = \is_array($row) ? (int) ($row[$key] ?? 0) : null;
            }
            $series[$key] = $values;
        }

        return $series;
    }

    public function linksForEvent(
        int $eventId,
        int $hospitalId,
        ?ClosureAnalyticsCriteria $criteria = null,
    ): ClosureProfileEventLinks {
        $params = [
            'profile_hospital_id' => $hospitalId,
            'event_id' => $eventId,
        ];
        $types = [
            'profile_hospital_id' => Types::INTEGER,
            'event_id' => Types::INTEGER,
        ];
        $group = $this->connection->fetchAssociative(<<<SQL
WITH {$this->signature()}
SELECT ps.profile_key,
       string_agg(
           DISTINCT s.name || E'\x1f' || d.name || E'\x1f' || cl.care_level || E'\x1f' || ai.reason,
           E'\x1e' ORDER BY s.name || E'\x1f' || d.name || E'\x1f' || cl.care_level || E'\x1f' || ai.reason
       ) AS members
FROM profile_signature ps
INNER JOIN closure_analysis_interval ai ON ai.event_id = ps.event_id AND ai.hospital_id = ps.hospital_id
INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
INNER JOIN speciality s ON s.id = ai.speciality_id
INNER JOIN department d ON d.id = ai.department_id
WHERE ps.event_id = :event_id
GROUP BY ps.profile_key
SQL, $params, $types);
        $groupKey = \is_array($group) ? $group['profile_key'] : null;
        if (\is_string($groupKey) && '' !== $groupKey && $criteria instanceof ClosureAnalyticsCriteria) {
            if (null !== $this->groupUnitRedundancy->redundantUnitForGroup($criteria, $hospitalId, $groupKey)) {
                $groupKey = null;
            }
        }

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT DISTINCT s.id, s.name
FROM closure_analysis_interval ai
INNER JOIN speciality s ON s.id = ai.speciality_id
WHERE ai.event_id = :event_id AND ai.hospital_id = :profile_hospital_id
ORDER BY s.name ASC, s.id ASC
SQL, $params, $types);

        return new ClosureProfileEventLinks(
            $hospitalId,
            \is_string($groupKey) && '' !== $groupKey ? $groupKey : null,
            array_map(
                static fn (array $row): ClosureProfileSpecialityLink => new ClosureProfileSpecialityLink((int) $row['id'], (string) $row['name']),
                $rows,
            ),
            \is_array($group) ? $this->labelFromPacked((string) $group['members']) : '',
        );
    }

    /**
     * @param array{name: string, public_id: string} $hospital
     * @param array{
     *     eventCount: int,
     *     usableEventCount: int,
     *     medianMinutes: ?float,
     *     q1Minutes: ?float,
     *     q3Minutes: ?float,
     *     meanMinutes: ?float,
     *     individualMinutes: list<float>
     * } $duration
     * @param list<ClosureProfileCareCount>    $careLevels
     * @param list<ClosureProfileMember>       $members
     * @param list<ClosureProfileDepartment>   $departments
     * @param list<ClosureProfileCourseSeries> $startCourse
     * @param list<ClosureProfileCourseSeries> $endCourse
     * @param list<list<int|null>>             $heatmap
     */
    private function assemble(
        ClosureProfileRef $profile,
        string $title,
        array $hospital,
        array $duration,
        array $careLevels,
        array $members,
        array $departments,
        ClosureProfileCoverage $coverage,
        array $startCourse,
        array $endCourse,
        ClosureProfilePhaseTotals $phase,
        array $heatmap,
    ): ClosureProfileView {
        return new ClosureProfileView(
            $profile,
            $title,
            $hospital['name'],
            $hospital['public_id'],
            $duration['eventCount'],
            $duration['usableEventCount'],
            $duration['medianMinutes'],
            $duration['q1Minutes'],
            $duration['q3Minutes'],
            $duration['meanMinutes'],
            $duration['individualMinutes'],
            $duration['eventCount'] > 0 && $duration['eventCount'] < self::QUARTILE_MINIMUM,
            $careLevels,
            $members,
            $departments,
            $coverage,
            $startCourse,
            $endCourse,
            $phase,
            $heatmap,
            ClosureProfileKind::Speciality === $profile->kind,
        );
    }

    /**
     * @param list<int> $hospitalIds
     */
    private function exists(ClosureProfileRef $profile, array $hospitalIds): bool
    {
        if ([] === $hospitalIds || ($profile->hospitalId > 0 && !\in_array($profile->hospitalId, $hospitalIds, true))) {
            return false;
        }
        $scopeParams = ['hospital_ids' => $hospitalIds];
        $scopeTypes = ['hospital_ids' => ArrayParameterType::INTEGER];

        return match ($profile->kind) {
            ClosureProfileKind::Hospital => false !== $this->connection->fetchOne(
                'SELECT 1 FROM hospital WHERE id = :id AND id IN (:hospital_ids) LIMIT 1',
                ['id' => $profile->hospitalId] + $scopeParams,
                ['id' => Types::INTEGER] + $scopeTypes,
            ),
            ClosureProfileKind::Speciality => $profile->hospitalId > 0
                ? false !== $this->connection->fetchOne(<<<'SQL'
SELECT 1
FROM closure_analysis_interval
WHERE hospital_id = :hospital_id AND speciality_id = :speciality_id
UNION ALL
SELECT 1
FROM allocation
WHERE hospital_id = :hospital_id AND speciality_id = :speciality_id
LIMIT 1
SQL, [
                    'hospital_id' => $profile->hospitalId,
                    'speciality_id' => $profile->specialityId(),
                ], [
                    'hospital_id' => Types::INTEGER,
                    'speciality_id' => Types::INTEGER,
                ])
                : false !== $this->connection->fetchOne(<<<'SQL'
SELECT 1
FROM closure_analysis_interval
WHERE speciality_id = :speciality_id AND hospital_id IN (:hospital_ids)
UNION ALL
SELECT 1
FROM allocation
WHERE speciality_id = :speciality_id AND hospital_id IN (:hospital_ids)
LIMIT 1
SQL, ['speciality_id' => $profile->specialityId()] + $scopeParams, ['speciality_id' => Types::INTEGER] + $scopeTypes),
            ClosureProfileKind::Department => false !== $this->connection->fetchOne(<<<'SQL'
SELECT 1
FROM closure_analysis_interval
WHERE department_id = :department_id
  AND hospital_id IN (:hospital_ids)
  AND (:pinned = 0 OR hospital_id = :pinned)
LIMIT 1
SQL, [
                'department_id' => (int) $profile->key,
                'pinned' => $profile->hospitalId,
            ] + $scopeParams, [
                'department_id' => Types::INTEGER,
                'pinned' => Types::INTEGER,
            ] + $scopeTypes),
            ClosureProfileKind::ClosureUnit => false !== $this->connection->fetchOne(<<<'SQL'
SELECT 1
FROM closure_analysis_interval
WHERE hospital_id = :hospital_id
  AND hospital_id IN (:hospital_ids)
  AND closure_unit = :unit
LIMIT 1
SQL, [
                'hospital_id' => $profile->hospitalId,
                'unit' => $profile->key,
            ] + $scopeParams, [
                'hospital_id' => Types::INTEGER,
                'unit' => Types::STRING,
            ] + $scopeTypes),
            ClosureProfileKind::Group => false !== $this->connection->fetchOne(<<<SQL
WITH {$this->signature()}
SELECT 1 FROM profile_signature WHERE profile_key = :profile_key LIMIT 1
SQL, [
                'profile_hospital_id' => $profile->hospitalId,
                'profile_key' => $profile->key,
            ], [
                'profile_hospital_id' => Types::INTEGER,
                'profile_key' => Types::STRING,
            ]),
        };
    }

    /**
     * @return array{name: string, public_id: string}|null
     */
    private function hospital(int $hospitalId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT name, public_id::text AS public_id FROM hospital WHERE id = :id',
            ['id' => $hospitalId],
            ['id' => Types::INTEGER],
        );
        if (false === $row) {
            return null;
        }

        return [
            'name' => (string) $row['name'],
            'public_id' => (string) $row['public_id'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return array{
     *     eventCount: int,
     *     usableEventCount: int,
     *     medianMinutes: ?float,
     *     q1Minutes: ?float,
     *     q3Minutes: ?float,
     *     meanMinutes: ?float,
     *     individualMinutes: list<float>
     * }
     */
    private function duration(string $base, array $params, array $types, ClosureProfileRef $profile): array
    {
        $speciality = $this->volumePredicate($profile, true);
        $row = $this->connection->fetchAssociative(<<<SQL
WITH {$base}
SELECT COUNT(*)::int AS event_count,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY seconds) AS median_seconds,
       percentile_cont(0.25) WITHIN GROUP (ORDER BY seconds) AS q1_seconds,
       percentile_cont(0.75) WITHIN GROUP (ORDER BY seconds) AS q3_seconds,
       AVG(seconds) AS mean_seconds,
       (
           SELECT COUNT(DISTINCT h.event_id)
           FROM closure_volume_hour h
           WHERE h.event_id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
             AND h.scope = 'speciality'
             AND h.quality IN ('reliable', 'limited')
             AND h.evaluable_seconds > 0
             {$speciality}
       )::int AS usable_event_count
FROM (
    SELECT event_key, SUM(EXTRACT(EPOCH FROM (segment_end - segment_start))) AS seconds
    FROM event_segments
    GROUP BY event_key
) durations
SQL, $params, $types);
        $eventCount = false === $row ? 0 : (int) $row['event_count'];
        $individuals = [];
        if ($eventCount > 0 && $eventCount < self::QUARTILE_MINIMUM) {
            $values = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT SUM(EXTRACT(EPOCH FROM (segment_end - segment_start))) / 60.0 AS minutes
FROM event_segments
GROUP BY event_key
ORDER BY minutes ASC
SQL, $params, $types);
            $individuals = array_map(static fn (array $value): float => (float) $value['minutes'], $values);
        }

        return [
            'eventCount' => $eventCount,
            'usableEventCount' => false === $row ? 0 : (int) $row['usable_event_count'],
            'medianMinutes' => $this->minutes($row, 'median_seconds'),
            'q1Minutes' => $this->minutes($row, 'q1_seconds'),
            'q3Minutes' => $this->minutes($row, 'q3_seconds'),
            'meanMinutes' => $this->minutes($row, 'mean_seconds'),
            'individualMinutes' => $individuals,
        ];
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function minutes(array|false $row, string $key): ?float
    {
        if (false === $row || null === $row[$key] || '' === $row[$key]) {
            return null;
        }

        return ((float) $row[$key]) / 60.0;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return list<ClosureProfileCareCount>
     */
    private function careLevels(string $base, array $params, array $types): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT care_level, COUNT(DISTINCT event_key)::int AS event_count
FROM valid_closures
GROUP BY care_level
ORDER BY care_level ASC
SQL, $params, $types);

        return array_map(
            static fn (array $row): ClosureProfileCareCount => new ClosureProfileCareCount((string) $row['care_level'], (int) $row['event_count']),
            $rows,
        );
    }

    /**
     * @return list<ClosureProfileMember>
     */
    private function groupMembers(ClosureProfileRef $profile): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$this->signature()}
SELECT DISTINCT s.id AS speciality_id,
       s.name AS speciality_name,
       d.id AS department_id,
       d.name AS department_name,
       cl.care_level,
       ai.reason
FROM profile_signature ps
INNER JOIN closure_analysis_interval ai ON ai.event_id = ps.event_id
INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
INNER JOIN speciality s ON s.id = ai.speciality_id
INNER JOIN department d ON d.id = ai.department_id
WHERE ps.profile_key = :profile_key
  AND ai.speciality_id::text || ':' || ai.department_id::text || ':' || cl.care_level || ':' || ai.reason IN (
      SELECT token FROM profile_member_token WHERE event_id = ps.event_id
  )
ORDER BY s.name ASC, d.name ASC, cl.care_level ASC, ai.reason ASC
SQL, [
            'profile_hospital_id' => $profile->hospitalId,
            'profile_key' => $profile->key,
        ], [
            'profile_hospital_id' => Types::INTEGER,
            'profile_key' => Types::STRING,
        ]);

        return array_map($this->member(...), $rows);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return list<ClosureProfileMember>
     */
    private function specialityMembers(string $base, array $params, array $types): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT DISTINCT s.id AS speciality_id,
       s.name AS speciality_name,
       d.id AS department_id,
       d.name AS department_name,
       v.care_level,
       v.reason
FROM valid_closures v
INNER JOIN speciality s ON s.id = v.speciality_id
INNER JOIN department d ON d.id = v.department_id
ORDER BY s.name ASC, d.name ASC, v.care_level ASC, v.reason ASC
SQL, $params, $types);

        return array_map($this->member(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function member(array $row): ClosureProfileMember
    {
        return new ClosureProfileMember(
            (int) $row['speciality_id'],
            (string) $row['speciality_name'],
            (int) $row['department_id'],
            (string) $row['department_name'],
            (string) $row['care_level'],
            (string) $row['reason'],
        );
    }

    /**
     * @param list<ClosureProfileMember> $members
     */
    private function groupTitle(array $members): string
    {
        $rows = [];
        foreach ($members as $member) {
            $rows[] = [$member->specialityName, $member->departmentName, $member->careLevel, $member->reason];
        }

        return $this->summarizeMembers($rows);
    }

    private function labelFromPacked(string $packed): string
    {
        $rows = [];
        foreach ($this->packedMembersParser->parse($packed) as $member) {
            $rows[] = [$member->specialityName, $member->departmentName, $member->careLevel, $member->reason];
        }

        return $this->summarizeMembers($rows);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $members
     */
    private function summarizeMembers(array $members): string
    {
        if ([] === $members) {
            return '';
        }

        $specialities = [];
        $departments = [];
        $careLevels = [];
        $reasons = [];
        foreach ($members as [$speciality, $department, $careLevel, $reason]) {
            $specialities[$speciality] = $speciality;
            $departments[$department] = $department;
            $careLevels[$careLevel] = $this->catalogLabel('stats.closure.care_level.', $careLevel);
            $reasons[$reason] = $this->catalogLabel('stats.closure.reason.', $reason);
        }

        $detail = [$this->departmentSummary(array_values($departments))];
        if (1 === \count($careLevels)) {
            $detail[] = array_values($careLevels)[0];
        }
        if (1 === \count($reasons)) {
            $detail[] = array_values($reasons)[0];
        }

        return implode(', ', array_values($specialities))."\n".implode(' · ', array_filter(
            $detail,
            static fn (string $part): bool => '' !== $part,
        ));
    }

    /**
     * @param list<string> $names
     */
    private function departmentSummary(array $names): string
    {
        if (\count($names) <= 2) {
            return implode(', ', $names);
        }

        return $this->translator->trans('stats.closure.profile.department_summary', [
            'first' => $names[0],
            'second' => $names[1],
            'more' => \count($names) - 2,
        ], 'statistics');
    }

    private function catalogLabel(string $prefix, string $code): string
    {
        $key = $prefix.$code;
        $label = $this->translator->trans($key, [], 'statistics');

        return $label === $key ? $code : $label;
    }

    private function specialityName(int $specialityId): string
    {
        $name = $this->connection->fetchOne(
            'SELECT name FROM speciality WHERE id = :id',
            ['id' => $specialityId],
            ['id' => Types::INTEGER],
        );

        return \is_string($name) && '' !== $name ? $name : (string) $specialityId;
    }

    private function departmentName(int $departmentId): string
    {
        $name = $this->connection->fetchOne(
            'SELECT name FROM department WHERE id = :id',
            ['id' => $departmentId],
            ['id' => Types::INTEGER],
        );

        return \is_string($name) && '' !== $name ? $name : (string) $departmentId;
    }

    private function volumePredicate(ClosureProfileRef $profile, bool $andPrefix): string
    {
        $predicate = match ($profile->kind) {
            ClosureProfileKind::Speciality => 'h.speciality_id = :profile_speciality_id',
            ClosureProfileKind::Department => 'h.department_id = :profile_department_id',
            default => null,
        };
        if (null === $predicate) {
            return $andPrefix ? '' : 'TRUE';
        }

        return $andPrefix ? 'AND '.$predicate : $predicate;
    }

    /**
     * @param array<string, mixed>       $params
     * @param array<string, mixed>       $types
     * @param list<ClosureProfileMember> $members
     *
     * @return list<ClosureProfileDepartment>
     */
    private function departments(
        ClosureProfileRef $profile,
        string $base,
        array $params,
        array $types,
        array $members,
    ): array {
        $period = $this->assignmentPeriod($params);
        if (ClosureProfileKind::Speciality !== $profile->kind || $profile->hospitalId < 1) {
            $rows = [];
            foreach ($members as $member) {
                $key = $member->specialityId.':'.$member->departmentId;
                if (isset($rows[$key])) {
                    continue;
                }
                $assignmentParams = [
                    'profile_hospital_id' => $profile->hospitalId,
                    'speciality_id' => $member->specialityId,
                    'department_id' => $member->departmentId,
                ];
                $assignmentTypes = [
                    'profile_hospital_id' => Types::INTEGER,
                    'speciality_id' => Types::INTEGER,
                    'department_id' => Types::INTEGER,
                ];
                if (isset($params['period_from'])) {
                    $assignmentParams['period_from'] = $params['period_from'];
                    $assignmentTypes['period_from'] = $types['period_from'];
                }
                if (isset($params['period_to'])) {
                    $assignmentParams['period_to'] = $params['period_to'];
                    $assignmentTypes['period_to'] = $types['period_to'];
                }
                $assigned = $profile->hospitalId > 0 && false !== $this->connection->fetchOne(<<<SQL
SELECT 1 FROM allocation a
WHERE a.hospital_id = :profile_hospital_id
  AND a.speciality_id = :speciality_id
  AND a.department_id = :department_id
  AND {$period}
LIMIT 1
SQL, $assignmentParams, $assignmentTypes);
                $rows[$key] = new ClosureProfileDepartment(
                    $member->departmentId,
                    $member->departmentName,
                    $member->specialityName,
                    true,
                    $assigned,
                );
            }

            return array_values($rows);
        }

        $found = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
closure_depts AS (
    SELECT DISTINCT department_id FROM valid_closures
),
assignment_depts AS (
    SELECT DISTINCT a.department_id
    FROM allocation a
    WHERE a.hospital_id = :profile_hospital_id
      AND a.speciality_id = :profile_speciality_id
      AND {$period}
)
SELECT d.id, d.name, s.name AS speciality_name,
       d.id IN (SELECT department_id FROM closure_depts) AS from_closure,
       d.id IN (SELECT department_id FROM assignment_depts) AS from_assignment
FROM department d
INNER JOIN speciality s ON s.id = :profile_speciality_id
WHERE d.id IN (SELECT department_id FROM closure_depts)
   OR d.id IN (SELECT department_id FROM assignment_depts)
ORDER BY d.name ASC
SQL, $params, $types);

        return array_map(
            fn (array $row): ClosureProfileDepartment => new ClosureProfileDepartment(
                (int) $row['id'],
                (string) $row['name'],
                (string) $row['speciality_name'],
                $this->asBool($row['from_closure']),
                $this->asBool($row['from_assignment']),
            ),
            $found,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    private function coverage(string $base, array $params, array $types): ClosureProfileCoverage
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
span AS (
    SELECT DISTINCT event_key::bigint AS event_id, starts_at, ends_at
    FROM valid_closures
),
bounds AS (
    SELECT MIN(starts_at) AS first_at, MAX(ends_at) AS last_at FROM span
),
years AS (
    SELECT gs.year::int AS year,
           COUNT(DISTINCT s.event_id)::int AS event_count
    FROM span s
    CROSS JOIN LATERAL generate_series(
        EXTRACT(YEAR FROM s.starts_at)::int,
        GREATEST(
            EXTRACT(YEAR FROM s.starts_at)::int,
            EXTRACT(YEAR FROM (s.ends_at - INTERVAL '1 second'))::int
        )
    ) AS gs(year)
    GROUP BY gs.year
)
SELECT y.year, y.event_count, b.first_at, b.last_at
FROM years y
CROSS JOIN bounds b
ORDER BY y.year
SQL, $params, $types);
        if ([] === $rows) {
            return ClosureProfileCoverage::empty();
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['year']] = (int) $row['event_count'];
        }
        $first = $rows[0];

        return new ClosureProfileCoverage(
            $this->coverageDate($first['first_at']),
            $this->coverageDate($first['last_at']),
            $this->yearHeatmap($counts),
        );
    }

    /**
     * @param array<int, int> $countsByYear
     *
     * @return list<list<array{year: int, count: int, intensity: float, future: bool, hasData: bool}>>
     */
    private function yearHeatmap(array $countsByYear): array
    {
        if ([] === $countsByYear) {
            return [];
        }

        $columns = self::COVERAGE_YEAR_COLUMNS;
        $currentYear = (int) new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'))->format('Y');
        $minYear = min(array_keys($countsByYear));
        $maxYear = max(array_keys($countsByYear));
        $startYear = $minYear < self::COVERAGE_YEAR_ORIGIN
            ? $minYear
            : self::COVERAGE_YEAR_ORIGIN + intdiv($minYear - self::COVERAGE_YEAR_ORIGIN, $columns) * $columns;
        $span = $maxYear - $startYear + 1;
        $remainder = $span % $columns;
        if (0 !== $remainder) {
            $maxYear += $columns - $remainder;
        }

        $peak = max(1, ...array_values($countsByYear));
        $cells = [];
        for ($year = $startYear; $year <= $maxYear; ++$year) {
            $future = $year > $currentYear;
            $count = $future ? 0 : ($countsByYear[$year] ?? 0);
            $hasData = $count > 0;
            $cells[] = [
                'year' => $year,
                'count' => $count,
                'intensity' => $hasData ? round($count / $peak, 4) : 0.0,
                'future' => $future,
                'hasData' => $hasData,
            ];
        }

        /** @var list<list<array{year: int, count: int, intensity: float, future: bool, hasData: bool}>> $rows */
        $rows = array_chunk($cells, $columns);

        return $rows;
    }

    private function coverageDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    /**
     * @return list<ClosureProfileCourseSeries>
     */
    private function course(ClosureAnalyticsCriteria $criteria, ClosureVolumeStratum $stratum, bool $alignToEnd): array
    {
        $profile = $criteria->profile;
        if (!$profile instanceof ClosureProfileRef) {
            return [];
        }
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $anchor = $alignToEnd ? 'pe.ends_at' : 'pe.starts_at';
        $stratumSql = $this->stratumSql($stratum, $params, $types);
        $specialitySql = $this->volumePredicate($profile, false);
        $includeCombined = ClosureProfileKind::Speciality !== $profile->kind;
        $combinedCte = $includeCombined ? <<<'SQL'
,
per_combined AS (
    SELECT event_id,
           0 AS series_id,
           hour_offset,
           SUM(observed_part) / NULLIF(SUM(evaluable_overlap) / 3600.0, 0) AS observed_rate,
           SUM(expected_part) / NULLIF(SUM(evaluable_overlap) / 3600.0, 0) AS expected_rate,
           SUM(reliable_overlap) AS reliable_overlap,
           SUM(usable_overlap) AS usable_overlap
    FROM (
        SELECT event_id, hour_offset, bucket_start,
               SUM(observed_part) AS observed_part,
               SUM(expected_part) AS expected_part,
               MAX(evaluable_overlap) AS evaluable_overlap,
               SUM(reliable_overlap) AS reliable_overlap,
               MAX(usable_overlap) AS usable_overlap
        FROM piece
        GROUP BY event_id, hour_offset, bucket_start
    ) once_per_bucket
    GROUP BY event_id, hour_offset
)
SQL : '';
        $rates = $includeCombined
            ? 'SELECT * FROM per_series UNION ALL SELECT * FROM per_combined'
            : 'SELECT * FROM per_series';

        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
profile_events AS (
    SELECT DISTINCT e.id AS event_id, e.starts_at, e.ends_at
    FROM closure_event e
    WHERE e.id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
),
offsets AS (
    SELECT gs AS hour_offset FROM generate_series(-{$this->contextHours()}, {$this->contextHours()} - 1) AS gs
),
bins AS (
    SELECT pe.event_id,
           o.hour_offset,
           {$anchor} + (o.hour_offset * INTERVAL '1 hour') AS bin_start,
           {$anchor} + ((o.hour_offset + 1) * INTERVAL '1 hour') AS bin_end
    FROM profile_events pe
    CROSS JOIN offsets o
),
usable AS (
    SELECT h.event_id, h.speciality_id, h.bucket_start, h.observed_area, h.expected_area,
           h.evaluable_seconds, h.bucket_seconds, h.quality, b.hour_offset,
           EXTRACT(EPOCH FROM (LEAST(h.bucket_end, b.bin_end) - GREATEST(h.bucket_start, b.bin_start))) AS overlap_seconds
    FROM closure_volume_hour h
    INNER JOIN bins b
        ON b.event_id = h.event_id
       AND h.bucket_start < b.bin_end
       AND h.bucket_end > b.bin_start
    WHERE {$stratumSql}
      AND {$specialitySql}
      AND h.quality IN ('reliable', 'limited')
      AND h.evaluable_seconds > 0
      AND h.bucket_seconds > 0
      AND h.observed_area IS NOT NULL
      AND h.expected_area IS NOT NULL
),
piece AS (
    SELECT event_id, speciality_id, hour_offset, bucket_start,
           SUM(observed_area * overlap_seconds / bucket_seconds) AS observed_part,
           SUM(expected_area * overlap_seconds / bucket_seconds) AS expected_part,
           MAX(evaluable_seconds * overlap_seconds / bucket_seconds) AS evaluable_overlap,
           SUM(overlap_seconds) FILTER (WHERE quality = 'reliable') AS reliable_overlap,
           SUM(overlap_seconds) AS usable_overlap
    FROM usable
    WHERE overlap_seconds > 0
    GROUP BY event_id, speciality_id, hour_offset, bucket_start
),
per_series AS (
    SELECT event_id, speciality_id AS series_id, hour_offset,
           SUM(observed_part) / NULLIF(SUM(evaluable_overlap) / 3600.0, 0) AS observed_rate,
           SUM(expected_part) / NULLIF(SUM(evaluable_overlap) / 3600.0, 0) AS expected_rate,
           SUM(reliable_overlap) AS reliable_overlap,
           SUM(usable_overlap) AS usable_overlap
    FROM piece
    GROUP BY event_id, speciality_id, hour_offset
){$combinedCte},
rates AS (
    {$rates}
)
SELECT r.series_id,
       r.hour_offset,
       COUNT(*)::int AS event_count,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY r.observed_rate) AS observed_median,
       percentile_cont(0.25) WITHIN GROUP (ORDER BY r.observed_rate) AS observed_q1,
       percentile_cont(0.75) WITHIN GROUP (ORDER BY r.observed_rate) AS observed_q3,
       AVG(r.observed_rate) AS observed_mean,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY r.expected_rate) AS expected_median,
       AVG(r.expected_rate) AS expected_mean,
       SUM(r.reliable_overlap) / NULLIF(SUM(r.usable_overlap), 0) AS reliable_share,
       COALESCE(s.name, '') AS series_name
FROM rates r
LEFT JOIN speciality s ON s.id = r.series_id
GROUP BY r.series_id, r.hour_offset, s.name
ORDER BY s.name ASC, r.series_id ASC, r.hour_offset ASC
SQL, $params, $types);

        $hospitalSql = $profile->hospitalId > 0 ? 'ai.hospital_id = :profile_hospital_id' : 'TRUE';
        $influenced = $this->courseFlags($base, $params, $types, $anchor, $stratumSql, $specialitySql, $hospitalSql, true);
        $boundaries = $this->courseFlags($base, $params, $types, $anchor, $stratumSql, $specialitySql, $hospitalSql, false);
        $closureShares = $this->courseClosureShares($base, $params, $types, $anchor);
        $grouped = [];
        foreach ($rows as $row) {
            $seriesId = (int) $row['series_id'];
            $grouped[$seriesId]['name'] = (string) $row['series_name'];
            $grouped[$seriesId]['points'][(int) $row['hour_offset']] = $row;
        }
        $specialitySeries = 0;
        foreach (array_keys($grouped) as $seriesId) {
            if (0 !== $seriesId) {
                ++$specialitySeries;
            }
        }
        if ($includeCombined && $specialitySeries < 2) {
            unset($grouped[0]);
        }

        $series = [];
        foreach ($grouped as $seriesId => $data) {
            $points = [];
            for ($offset = -$this->contextHours(); $offset < $this->contextHours(); ++$offset) {
                $row = $data['points'][$offset] ?? null;
                $eventCount = \is_array($row) ? (int) $row['event_count'] : 0;
                $points[] = new ClosureProfileCoursePoint(
                    $offset,
                    $eventCount,
                    \is_array($row) ? $this->nullableFloat($row['observed_median']) : null,
                    \is_array($row) ? $this->nullableFloat($row['observed_q1']) : null,
                    \is_array($row) ? $this->nullableFloat($row['observed_q3']) : null,
                    \is_array($row) ? $this->nullableFloat($row['observed_mean']) : null,
                    \is_array($row) ? $this->nullableFloat($row['expected_median']) : null,
                    \is_array($row) ? $this->nullableFloat($row['expected_mean']) : null,
                    \is_array($row) ? (float) ($row['reliable_share'] ?? 0) : 0.0,
                    $influenced[$offset] ?? false,
                    $boundaries[$offset] ?? false,
                    $eventCount < $this->referenceConfig->minimumReferenceSlots,
                    $closureShares[$offset] ?? 0.0,
                );
            }
            $series[] = new ClosureProfileCourseSeries($seriesId, $data['name'], 0 === $seriesId, $points);
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return array<int, bool>
     */
    private function courseFlags(
        string $base,
        array $params,
        array $types,
        string $anchor,
        string $stratumSql,
        string $specialitySql,
        string $hospitalSql,
        bool $influenced,
    ): array {
        $intervalSpeciality = str_replace(
            ['h.speciality_id', 'h.department_id'],
            ['ai.speciality_id', 'ai.department_id'],
            $specialitySql,
        );
        $select = $influenced
            ? <<<SQL
SELECT b.hour_offset, BOOL_OR(h.influenced OR h.quality = 'influenced') AS marked
FROM bins b
INNER JOIN closure_volume_hour h
    ON h.event_id = b.event_id
   AND h.bucket_start < b.bin_end
   AND h.bucket_end > b.bin_start
WHERE {$stratumSql}
  AND {$specialitySql}
GROUP BY b.hour_offset
SQL
            : <<<SQL
SELECT b.hour_offset, TRUE AS marked
FROM bins b
INNER JOIN closure_analysis_interval ai ON ai.event_id = b.event_id
WHERE {$hospitalSql}
  AND {$intervalSpeciality}
  AND (
      (ai.starts_at >= b.bin_start AND ai.starts_at < b.bin_end)
      OR (ai.ends_at >= b.bin_start AND ai.ends_at < b.bin_end)
  )
GROUP BY b.hour_offset
SQL;

        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
profile_events AS (
    SELECT DISTINCT e.id AS event_id, e.starts_at, e.ends_at
    FROM closure_event e
    WHERE e.id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
),
offsets AS (
    SELECT gs AS hour_offset FROM generate_series(-{$this->contextHours()}, {$this->contextHours()} - 1) AS gs
),
bins AS (
    SELECT pe.event_id,
           o.hour_offset,
           {$anchor} + (o.hour_offset * INTERVAL '1 hour') AS bin_start,
           {$anchor} + ((o.hour_offset + 1) * INTERVAL '1 hour') AS bin_end
    FROM profile_events pe
    CROSS JOIN offsets o
)
{$select}
SQL, $params, $types);
        $flags = [];
        foreach ($rows as $row) {
            $flags[(int) $row['hour_offset']] = $this->asBool($row['marked']);
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return array<int, float>
     */
    private function courseClosureShares(
        string $base,
        array $params,
        array $types,
        string $anchor,
    ): array {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
profile_events AS (
    SELECT DISTINCT e.id AS event_id, e.starts_at, e.ends_at
    FROM closure_event e
    WHERE e.id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
),
offsets AS (
    SELECT gs AS hour_offset FROM generate_series(-{$this->contextHours()}, {$this->contextHours()} - 1) AS gs
),
bins AS (
    SELECT pe.event_id,
           o.hour_offset,
           {$anchor} + (o.hour_offset * INTERVAL '1 hour') AS bin_start,
           {$anchor} + ((o.hour_offset + 1) * INTERVAL '1 hour') AS bin_end,
           pe.starts_at,
           pe.ends_at
    FROM profile_events pe
    CROSS JOIN offsets o
)
SELECT b.hour_offset,
       AVG(CASE WHEN b.starts_at < b.bin_end AND b.ends_at > b.bin_start THEN 1.0 ELSE 0.0 END) AS closure_share
FROM bins b
GROUP BY b.hour_offset
SQL, $params, $types);
        $shares = [];
        foreach ($rows as $row) {
            $shares[(int) $row['hour_offset']] = (float) $row['closure_share'];
        }

        return $shares;
    }

    private function phase(ClosureAnalyticsCriteria $criteria, ClosureVolumeStratum $stratum): ClosureProfilePhaseTotals
    {
        $profile = $criteria->profile;
        if (!$profile instanceof ClosureProfileRef) {
            return $this->emptyPhase();
        }
        [$base, $params, $types] = ClosureTemporalSql::base($criteria);
        $stratumSql = $this->stratumSql($stratum, $params, $types);
        $specialitySql = $this->volumePredicate($profile, true);
        $rates = $this->connection->fetchAssociative(<<<SQL
WITH {$base},
usable AS (
    SELECT h.event_id, h.speciality_id, h.department_id, h.urgency_code, h.stratum,
           h.bucket_start, h.observed_area, h.expected_area, h.evaluable_seconds, h.reference_cutoff
    FROM closure_volume_hour h
    WHERE h.event_id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
      AND {$stratumSql}
      {$specialitySql}
      AND h.in_closure IS TRUE
      AND h.quality IN ('reliable', 'limited')
      AND h.evaluable_seconds > 0
      AND h.observed_area IS NOT NULL
      AND h.expected_area IS NOT NULL
),
per_bucket AS (
    SELECT event_id, bucket_start,
           SUM(observed_area) AS observed_area,
           SUM(expected_area) AS expected_area,
           MAX(evaluable_seconds) AS evaluable_seconds
    FROM usable
    GROUP BY event_id, bucket_start
),
per_event AS (
    SELECT event_id,
           SUM(observed_area) / NULLIF(SUM(evaluable_seconds) / 3600.0, 0) AS observed_rate,
           SUM(expected_area) / NULLIF(SUM(evaluable_seconds) / 3600.0, 0) AS expected_rate
    FROM per_bucket
    GROUP BY event_id
)
SELECT COUNT(*)::int AS contributing,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY observed_rate) AS observed_median,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY expected_rate) AS expected_median,
       AVG(observed_rate) AS observed_mean,
       AVG(expected_rate) AS expected_mean
FROM per_event
SQL, $params, $types);
        $volume = $this->connection->fetchAssociative(<<<SQL
WITH {$base},
usable AS (
    SELECT h.speciality_id, h.department_id, h.urgency_code, h.stratum, h.bucket_start,
           h.observed_area, h.expected_area, h.reference_cutoff, h.event_id
    FROM closure_volume_hour h
    WHERE h.event_id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
      AND {$stratumSql}
      {$specialitySql}
      AND h.in_closure IS TRUE
      AND h.quality IN ('reliable', 'limited')
      AND h.evaluable_seconds > 0
      AND h.observed_area IS NOT NULL
      AND h.expected_area IS NOT NULL
)
SELECT SUM(observed_area) AS observed_area, SUM(expected_area) AS expected_area
FROM (
    SELECT (array_agg(observed_area ORDER BY reference_cutoff ASC))[1] AS observed_area,
           (array_agg(expected_area ORDER BY reference_cutoff ASC))[1] AS expected_area
    FROM usable
    GROUP BY speciality_id, department_id, urgency_code, stratum, bucket_start
) deduped
SQL, $params, $types);
        $reused = false !== $this->connection->fetchOne(<<<SQL
WITH {$base},
usable AS (
    SELECT h.event_id, h.speciality_id, h.department_id, h.urgency_code, h.stratum, h.bucket_start
    FROM closure_volume_hour h
    WHERE h.event_id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
      AND {$stratumSql}
      {$specialitySql}
      AND h.in_closure IS TRUE
      AND h.quality IN ('reliable', 'limited')
      AND h.evaluable_seconds > 0
      AND h.observed_area IS NOT NULL
      AND h.expected_area IS NOT NULL
)
SELECT 1
FROM usable
GROUP BY speciality_id, department_id, urgency_code, stratum, bucket_start
HAVING COUNT(DISTINCT event_id) > 1
LIMIT 1
SQL, $params, $types);
        $contributing = false === $rates ? 0 : (int) $rates['contributing'];

        return new ClosureProfilePhaseTotals(
            $contributing,
            false === $rates ? null : $this->nullableFloat($rates['observed_median']),
            false === $rates ? null : $this->nullableFloat($rates['expected_median']),
            false === $rates ? null : $this->nullableFloat($rates['observed_mean']),
            false === $rates ? null : $this->nullableFloat($rates['expected_mean']),
            false === $volume ? null : $this->nullableFloat($volume['observed_area']),
            false === $volume ? null : $this->nullableFloat($volume['expected_area']),
            false !== $reused,
            $contributing < $this->referenceConfig->minimumReferenceSlots,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return list<list<int|null>>
     */
    private function heatmap(string $base, array $params, array $types): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base}
SELECT EXTRACT(ISODOW FROM e.starts_at)::int AS weekday,
       FLOOR(EXTRACT(HOUR FROM e.starts_at) / 2)::int AS slot,
       COUNT(*)::int AS event_count
FROM closure_event e
WHERE e.id IN (SELECT DISTINCT event_key::bigint FROM valid_closures)
GROUP BY 1, 2
SQL, $params, $types);
        $matrix = array_fill(0, 7, array_fill(0, 12, null));
        foreach ($rows as $row) {
            $weekday = (int) $row['weekday'];
            $slot = (int) $row['slot'];
            if ($weekday >= 1 && $weekday <= 7 && $slot >= 0 && $slot <= 11) {
                $matrix[$weekday - 1][$slot] = (int) $row['event_count'];
            }
        }

        $normalized = [];
        foreach ($matrix as $row) {
            $cells = [];
            foreach ($row as $value) {
                $cells[] = \is_int($value) ? $value : null;
            }
            $normalized[] = $cells;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    private function stratumSql(ClosureVolumeStratum $stratum, array &$params, array &$types): string
    {
        $params['profile_stratum'] = $stratum->storageStratum();
        $types['profile_stratum'] = Types::STRING;
        $sql = "h.scope = 'speciality' AND h.stratum = :profile_stratum";
        $urgency = $stratum->urgencyCode();
        if (null !== $urgency) {
            $params['profile_urgency'] = $urgency;
            $types['profile_urgency'] = ParameterType::INTEGER;
            $sql .= ' AND h.urgency_code = :profile_urgency';
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assignmentPeriod(array $params): string
    {
        $parts = [];
        if (isset($params['period_from'])) {
            $parts[] = "(a.created_at AT TIME ZONE 'Europe/Berlin') >= :period_from";
        }
        if (isset($params['period_to'])) {
            $parts[] = "(a.created_at AT TIME ZONE 'Europe/Berlin') < :period_to";
        }

        return [] === $parts ? 'TRUE' : implode(' AND ', $parts);
    }

    /**
     * @param array<string, mixed>         $params
     * @param array<string, mixed>         $types
     * @param list<ClosureProfileListItem> $specialities
     *
     * @return list<ClosureProfileListItem>
     */
    private function appendAssignmentSpecialities(
        ClosureAnalyticsCriteria $criteria,
        array $params,
        array $types,
        array $specialities,
    ): array {
        $known = [];
        foreach ($specialities as $item) {
            $known[$item->hospitalId.':'.$item->key] = true;
        }
        $hospitals = $criteria->scope->hospitalIds;
        if (!\is_array($hospitals) || [] === $hospitals) {
            return $specialities;
        }
        $params['assignment_hospital_ids'] = $hospitals;
        $types['assignment_hospital_ids'] = ArrayParameterType::INTEGER;
        $period = $this->assignmentPeriod($params);
        $remaining = self::OVERVIEW_LIMIT - \count($specialities);
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT a.hospital_id, h.name AS hospital_name, a.speciality_id, s.name AS title
FROM allocation a
INNER JOIN hospital h ON h.id = a.hospital_id
INNER JOIN speciality s ON s.id = a.speciality_id
WHERE a.hospital_id IN (:assignment_hospital_ids)
  AND {$period}
GROUP BY a.hospital_id, h.name, a.speciality_id, s.name
ORDER BY s.name ASC, h.name ASC
LIMIT {$remaining}
SQL, $params, $types);
        foreach ($rows as $row) {
            $key = ((int) $row['hospital_id']).':'.((int) $row['speciality_id']);
            if (isset($known[$key])) {
                continue;
            }
            $specialities[] = new ClosureProfileListItem(
                ClosureProfileKind::Speciality,
                (int) $row['hospital_id'],
                (string) $row['hospital_name'],
                (string) (int) $row['speciality_id'],
                (string) $row['title'],
                0,
            );
            if (\count($specialities) >= self::OVERVIEW_LIMIT) {
                break;
            }
        }

        return $specialities;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function groupItem(array $row): ClosureProfileListItem
    {
        return new ClosureProfileListItem(
            ClosureProfileKind::Group,
            (int) $row['hospital_id'],
            (string) $row['hospital_name'],
            (string) $row['profile_key'],
            $this->labelFromPacked((string) ($row['members'] ?? '')),
            (int) $row['event_count'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function specialityItem(array $row): ClosureProfileListItem
    {
        return new ClosureProfileListItem(
            ClosureProfileKind::Speciality,
            (int) $row['hospital_id'],
            (string) $row['hospital_name'],
            (string) (int) $row['speciality_id'],
            (string) $row['title'],
            (int) $row['event_count'],
        );
    }

    private function emptyPhase(): ClosureProfilePhaseTotals
    {
        return new ClosureProfilePhaseTotals(0, null, null, null, null, null, null, false, true);
    }

    private function asBool(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value || 'true' === $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (float) $value;
    }

    private function signature(): string
    {
        return ClosureProfileSql::signatureCtes();
    }

    private function contextHours(): int
    {
        return ClosureVolumeReferenceConfig::CONTEXT_HOURS;
    }

    private function limit(): int
    {
        return self::OVERVIEW_LIMIT;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return list<ClosureProfileListItem>
     */
    private function nonRedundantGroupItems(
        ClosureAnalyticsCriteria $criteria,
        string $base,
        array $params,
        array $types,
        ?int $limit,
    ): array {
        $limitSql = null === $limit ? '' : 'LIMIT '.$limit;
        $groupRows = $this->connection->fetchAllAssociative(<<<SQL
WITH {$base},
period_events AS (
    SELECT DISTINCT event_key::bigint AS event_id, hospital_id
    FROM valid_closures
),
overview_token AS (
    SELECT pe.event_id,
           pe.hospital_id,
           ai.speciality_id::text || ':' || ai.department_id::text || ':' || cl.care_level || ':' || ai.reason AS token,
           s.name AS speciality_name,
           d.name AS department_name,
           cl.care_level,
           ai.reason
    FROM period_events pe
    INNER JOIN closure_analysis_interval ai ON ai.event_id = pe.event_id AND ai.hospital_id = pe.hospital_id
    INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
    INNER JOIN speciality s ON s.id = ai.speciality_id
    INNER JOIN department d ON d.id = ai.department_id
),
overview_signature AS (
    SELECT event_id,
           hospital_id,
           md5(string_agg(DISTINCT token, ',' ORDER BY token)) AS profile_key,
           string_agg(
               DISTINCT speciality_name || E'\x1f' || department_name || E'\x1f' || care_level || E'\x1f' || reason,
               E'\x1e' ORDER BY speciality_name || E'\x1f' || department_name || E'\x1f' || care_level || E'\x1f' || reason
           ) AS members
    FROM overview_token
    GROUP BY event_id, hospital_id
)
SELECT os.hospital_id,
       h.name AS hospital_name,
       os.profile_key,
       MIN(os.members) AS members,
       COUNT(*)::int AS event_count
FROM overview_signature os
INNER JOIN hospital h ON h.id = os.hospital_id
GROUP BY os.hospital_id, h.name, os.profile_key
ORDER BY COUNT(*) DESC, MIN(os.members) ASC, h.name ASC
{$limitSql}
SQL, $params, $types);

        $redundant = $this->groupUnitRedundancy->redundantGroupKeys($criteria);
        $groups = [];
        foreach ($groupRows as $row) {
            $hospitalId = (int) $row['hospital_id'];
            $profileKey = (string) $row['profile_key'];
            if (isset($redundant[$hospitalId.':'.$profileKey])) {
                continue;
            }
            $groups[] = $this->groupItem($row);
        }

        return $groups;
    }

    private function recurringGroupsGroupedSql(string $base): string
    {
        return <<<SQL
{$base},
period_events AS (
    SELECT DISTINCT event_key::bigint AS event_id, hospital_id
    FROM valid_closures
),
overview_token AS (
    SELECT pe.event_id,
           pe.hospital_id,
           ai.speciality_id::text || ':' || ai.department_id::text || ':' || cl.care_level || ':' || ai.reason AS token,
           s.name AS speciality_name,
           d.name AS department_name,
           cl.care_level,
           ai.reason
    FROM period_events pe
    INNER JOIN closure_analysis_interval ai ON ai.event_id = pe.event_id AND ai.hospital_id = pe.hospital_id
    INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
    INNER JOIN speciality s ON s.id = ai.speciality_id
    INNER JOIN department d ON d.id = ai.department_id
),
overview_signature AS (
    SELECT event_id,
           hospital_id,
           md5(string_agg(DISTINCT token, ',' ORDER BY token)) AS profile_key,
           string_agg(
               DISTINCT speciality_name || E'\x1f' || department_name || E'\x1f' || care_level || E'\x1f' || reason,
               E'\x1e' ORDER BY speciality_name || E'\x1f' || department_name || E'\x1f' || care_level || E'\x1f' || reason
           ) AS members
    FROM overview_token
    GROUP BY event_id, hospital_id
),
grouped AS (
    SELECT os.hospital_id,
           h.name AS hospital_name,
           os.profile_key,
           MIN(os.members) AS members,
           COUNT(*)::int AS event_count
    FROM overview_signature os
    INNER JOIN hospital h ON h.id = os.hospital_id
    GROUP BY os.hospital_id, h.name, os.profile_key
)
SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recurringProfileRow(array $row): ClosureRecurringProfileRow
    {
        $members = $this->packedMembersParser->parse((string) ($row['members'] ?? ''));
        $specialities = [];
        $departments = [];
        $careLevels = [];
        $reasons = [];
        foreach ($members as $member) {
            $specialities[$member->specialityName] = $member->specialityName;
            $departments[$member->departmentName] = $member->departmentName;
            $careLevels[$member->careLevel] = $member->careLevel;
            $reasons[$member->reason] = $member->reason;
        }

        return new ClosureRecurringProfileRow(
            (int) $row['hospital_id'],
            (string) $row['hospital_name'],
            (string) $row['profile_key'],
            (int) $row['event_count'],
            $members,
            array_values($specialities),
            array_values($departments),
            array_values($careLevels),
            array_values($reasons),
        );
    }

    private function assignmentPopulationSql(ClosureProfileRef $profile): string
    {
        return match ($profile->kind) {
            ClosureProfileKind::Department => 'AND a.department_id = :profile_department_id',
            ClosureProfileKind::Speciality => 'AND a.speciality_id = :profile_speciality_id',
            default => <<<'SQL'
AND a.speciality_id IN (
    SELECT DISTINCT ai.speciality_id
    FROM closure_analysis_interval ai
    WHERE ai.event_id = pe.event_id
      AND ai.hospital_id = pe.hospital_id
)
SQL,
        };
    }

    private function assignmentCandidatePopulationSql(ClosureProfileRef $profile): string
    {
        return match ($profile->kind) {
            ClosureProfileKind::Department => 'AND a.department_id = :profile_department_id',
            ClosureProfileKind::Speciality => 'AND a.speciality_id = :profile_speciality_id',
            default => '',
        };
    }

    private function volumeHourPopulationSql(ClosureProfileRef $profile): string
    {
        return match ($profile->kind) {
            ClosureProfileKind::Department => 'AND h.department_id = :profile_department_id',
            ClosureProfileKind::Speciality => 'AND h.speciality_id = :profile_speciality_id',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    private function bindProfileAssignmentParams(ClosureProfileRef $profile, array &$params, array &$types): void
    {
        if (ClosureProfileKind::Department === $profile->kind) {
            $params['profile_department_id'] = (int) $profile->key;
            $types['profile_department_id'] = ParameterType::INTEGER;
        }
        if (ClosureProfileKind::Speciality === $profile->kind) {
            $params['profile_speciality_id'] = (int) $profile->key;
            $types['profile_speciality_id'] = ParameterType::INTEGER;
        }
    }

    private function allocationStratumSql(string $alias, ClosureVolumeStratum $stratum): string
    {
        $urgency = $stratum->urgencyCode();
        if (null !== $urgency) {
            return sprintf('%s.urgency = %d', $alias, $urgency);
        }

        return match ($stratum) {
            ClosureVolumeStratum::Resus => sprintf('%s.requires_resus IS TRUE', $alias),
            ClosureVolumeStratum::Cathlab => sprintf('%s.requires_cathlab IS TRUE', $alias),
            default => 'TRUE',
        };
    }

    private function eventPhaseSql(string $phase, int $hours): string
    {
        return match ($phase) {
            'before' => "(a.created_at AT TIME ZONE 'Europe/Berlin') >= pe.starts_at - ({$hours} * INTERVAL '1 hour') AND (a.created_at AT TIME ZONE 'Europe/Berlin') < pe.starts_at",
            'during' => "(a.created_at AT TIME ZONE 'Europe/Berlin') >= pe.starts_at AND (a.created_at AT TIME ZONE 'Europe/Berlin') < pe.ends_at",
            'after' => "(a.created_at AT TIME ZONE 'Europe/Berlin') >= pe.ends_at AND (a.created_at AT TIME ZONE 'Europe/Berlin') < pe.ends_at + ({$hours} * INTERVAL '1 hour')",
            default => 'FALSE',
        };
    }

    /**
     * @param list<string> $parts
     */
    private function sqlJoin(array $parts): string
    {
        return implode(",\n           ", $parts);
    }

    private function nullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) $value;
    }
}
