<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Infrastructure\Query;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\ClosureAnalytics\Application\ClosureEventAssignmentPopulation;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventAllocationRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventAssignmentRowContext;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosurePhaseStratumCount;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeReferenceConfig;
use App\Statistics\ClosureAnalytics\Application\Volume\ClosureVolumeStratum;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

final readonly class ClosureEventAllocationQuery
{
    public const int PAGE_SIZE = 10;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{total: int, rows: list<ClosureEventAllocationRow>}
     */
    public function emergencies(
        int $eventId,
        int $hospitalId,
    ): array {
        $params = [
            'eventId' => $eventId,
            'hospitalId' => $hospitalId,
        ];
        $types = [
            'eventId' => ParameterType::INTEGER,
            'hospitalId' => ParameterType::INTEGER,
        ];
        $where = 'a.hospital_id = :hospitalId AND '.$this->departmentClosureOverlapSql('a');

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT a.id) FROM allocation a WHERE '.$where,
            $params,
            $types,
        );

        $membersCte = <<<'SQL'
members AS (
    SELECT DISTINCT speciality_id, department_id
    FROM closure_analysis_interval
    WHERE event_id = :eventId AND hospital_id = :hospitalId
)
SQL;

        return [
            'total' => $total,
            'rows' => $this->fetchRows(
                $where,
                $params,
                $types,
                self::PAGE_SIZE,
                0,
                $membersCte,
                $this->departmentClosureOverlapSql('a'),
            ),
        ];
    }

    /**
     * @return array{before: int, during: int, after: int}
     */
    public function phaseCounts(
        int $eventId,
        int $hospitalId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ClosureEventAssignmentPopulation $population,
        ClosureVolumeStratum $stratum,
    ): array {
        $beforeFrom = $startsAt->modify(sprintf('-%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        $afterTo = $endsAt->modify(sprintf('+%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        [$populationSql, $stratumSql, $params, $types] = $this->population(
            $eventId,
            $hospitalId,
            $population,
            $stratum,
        );
        $params += [
            'beforeFrom' => $beforeFrom,
            'start' => $startsAt,
            'end' => $endsAt,
            'afterTo' => $afterTo,
        ];
        foreach (['beforeFrom', 'start', 'end', 'afterTo'] as $name) {
            $types[$name] = Types::DATETIMETZ_IMMUTABLE;
        }

        /** @var array<string, int|string|null>|false $row */
        $row = $this->connection->fetchAssociative(<<<SQL
WITH members AS (
    SELECT DISTINCT speciality_id, department_id
    FROM closure_analysis_interval
    WHERE event_id = :eventId AND hospital_id = :hospitalId
)
SELECT
    COUNT(DISTINCT a.id) FILTER (WHERE {$this->boundSql('beforeFrom', 'start')}) AS before_count,
    COUNT(DISTINCT a.id) FILTER (WHERE {$this->boundSql('start', 'end')}) AS during_count,
    COUNT(DISTINCT a.id) FILTER (WHERE {$this->boundSql('end', 'afterTo')}) AS after_count
FROM allocation a
WHERE a.hospital_id = :hospitalId
  AND {$this->boundSql('beforeFrom', 'afterTo')}
  {$populationSql}
  {$stratumSql}
SQL, $params, $types);

        if (false === $row) {
            return ['before' => 0, 'during' => 0, 'after' => 0];
        }

        return [
            'before' => (int) $row['before_count'],
            'during' => (int) $row['during_count'],
            'after' => (int) $row['after_count'],
        ];
    }

    /**
     * @return list<ClosurePhaseStratumCount>
     */
    public function phaseBreakdown(
        int $eventId,
        int $hospitalId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ClosureEventAssignmentPopulation $population,
    ): array {
        $beforeFrom = $startsAt->modify(sprintf('-%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        $afterTo = $endsAt->modify(sprintf('+%d hours', ClosureVolumeReferenceConfig::CONTEXT_HOURS));
        [$populationSql, , $params, $types] = $this->population(
            $eventId,
            $hospitalId,
            $population,
            ClosureVolumeStratum::All,
        );
        $params += [
            'beforeFrom' => $beforeFrom,
            'start' => $startsAt,
            'end' => $endsAt,
            'afterTo' => $afterTo,
        ];
        foreach (['beforeFrom', 'start', 'end', 'afterTo'] as $name) {
            $types[$name] = Types::DATETIMETZ_IMMUTABLE;
        }

        $selectParts = [];
        foreach (ClosureVolumeStratum::choices() as $stratum) {
            $filter = $this->stratumFilterSql('a', $stratum);
            $prefix = $stratum->value;
            $selectParts[] = sprintf(
                'COUNT(DISTINCT a.id) FILTER (WHERE %s AND %s) AS %s_before',
                $this->boundSql('beforeFrom', 'start'),
                $filter,
                $prefix,
            );
            $selectParts[] = sprintf(
                'COUNT(DISTINCT a.id) FILTER (WHERE %s AND %s) AS %s_during',
                $this->boundSql('start', 'end'),
                $filter,
                $prefix,
            );
            $selectParts[] = sprintf(
                'COUNT(DISTINCT a.id) FILTER (WHERE %s AND %s) AS %s_after',
                $this->boundSql('end', 'afterTo'),
                $filter,
                $prefix,
            );
        }

        /** @var array<string, int|string|null>|false $row */
        $row = $this->connection->fetchAssociative(<<<SQL
WITH members AS (
    SELECT DISTINCT speciality_id, department_id
    FROM closure_analysis_interval
    WHERE event_id = :eventId AND hospital_id = :hospitalId
)
SELECT
    {$this->sqlJoin($selectParts)}
FROM allocation a
WHERE a.hospital_id = :hospitalId
  AND {$this->boundSql('beforeFrom', 'afterTo')}
  {$populationSql}
SQL, $params, $types);

        if (false === $row) {
            return [];
        }

        $breakdown = [];
        foreach (ClosureVolumeStratum::choices() as $stratum) {
            $prefix = $stratum->value;
            $breakdown[] = new ClosurePhaseStratumCount(
                $stratum,
                (int) ($row[$prefix.'_before'] ?? 0),
                (int) ($row[$prefix.'_during'] ?? 0),
                (int) ($row[$prefix.'_after'] ?? 0),
            );
        }

        return $breakdown;
    }

    /**
     * @return list<ClosureEventAllocationRow>
     */
    public function assignments(
        int $eventId,
        int $hospitalId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ClosureEventAssignmentPopulation $population,
        ClosureVolumeStratum $stratum,
        int $offset,
        int $limit,
    ): array {
        [$populationSql, $stratumSql, $params, $types] = $this->population(
            $eventId,
            $hospitalId,
            $population,
            $stratum,
        );
        $params['from'] = $from;
        $params['to'] = $to;
        $types['from'] = Types::DATETIMETZ_IMMUTABLE;
        $types['to'] = Types::DATETIMETZ_IMMUTABLE;
        $where = 'a.hospital_id = :hospitalId AND '.$this->windowSql().' '.$populationSql.' '.$stratumSql;

        return $this->fetchRows(
            $where,
            $params,
            $types,
            $limit,
            $offset,
            <<<'SQL'
members AS (
    SELECT DISTINCT speciality_id, department_id
    FROM closure_analysis_interval
    WHERE event_id = :eventId AND hospital_id = :hospitalId
)
SQL,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return list<ClosureEventAllocationRow>
     */
    private function fetchRows(
        string $where,
        array $params,
        array $types,
        int $limit,
        int $offset,
        string $with,
        ?string $closedAtAssignmentSql = null,
    ): array {
        $params['limit'] = max(0, $limit);
        $params['offset'] = max(0, $offset);
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;
        $cte = '' === $with ? '' : 'WITH '.$with;
        $memberDepartment = 'EXISTS (SELECT 1 FROM members m WHERE m.department_id = a.department_id)';
        $closedAtAssignment = $closedAtAssignmentSql ?? $this->closureMatchSql('a');

        /** @var list<array<string, int|string|bool|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
{$cte}
SELECT a.public_id::text AS public_id, a.created_at, a.urgency, a.department_was_closed,
       s.name AS speciality_name, d.name AS department_name,
       {$memberDepartment} AS is_event_member_department,
       {$closedAtAssignment} AS closed_at_assignment_time
FROM allocation a
JOIN speciality s ON s.id = a.speciality_id
JOIN department d ON d.id = a.department_id
WHERE {$where}
ORDER BY a.created_at ASC, a.id ASC
LIMIT :limit OFFSET :offset
SQL, $params, $types);

        return array_map($this->row(...), $rows);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function population(
        int $eventId,
        int $hospitalId,
        ClosureEventAssignmentPopulation $population,
        ClosureVolumeStratum $stratum,
    ): array {
        $params = [
            'eventId' => $eventId,
            'hospitalId' => $hospitalId,
        ];
        $types = [
            'eventId' => ParameterType::INTEGER,
            'hospitalId' => ParameterType::INTEGER,
        ];
        $populationSql = match ($population) {
            ClosureEventAssignmentPopulation::Speciality => 'AND a.speciality_id IN (SELECT speciality_id FROM members)',
            ClosureEventAssignmentPopulation::Closed => 'AND a.department_id IN (SELECT department_id FROM members)',
        };
        $stratumSql = $this->stratumSql('a', $stratum);

        return [$populationSql, $stratumSql, $params, $types];
    }

    private function stratumSql(string $alias, ClosureVolumeStratum $stratum): string
    {
        $filter = $this->stratumFilterSql($alias, $stratum);
        if ('TRUE' === $filter) {
            return '';
        }

        return 'AND '.$filter;
    }

    private function stratumFilterSql(string $alias, ClosureVolumeStratum $stratum): string
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

    private function departmentClosureOverlapSql(string $alias): string
    {
        return <<<SQL
EXISTS (
    SELECT 1
    FROM closure_analysis_interval ai
    WHERE ai.event_id = :eventId
      AND ai.hospital_id = :hospitalId
      AND ai.department_id = {$alias}.department_id
      AND {$alias}.created_at >= ai.starts_at
      AND {$alias}.created_at < ai.ends_at
)
SQL;
    }

    private function closureMatchSql(string $alias): string
    {
        return <<<SQL
EXISTS (
    SELECT 1
    FROM closure_analysis_interval ai
    INNER JOIN closure_analysis_care_level cl ON cl.analysis_interval_id = ai.id
    WHERE ai.event_id = :eventId
      AND ai.hospital_id = :hospitalId
      AND ai.department_id = {$alias}.department_id
      AND {$alias}.created_at >= ai.starts_at
      AND {$alias}.created_at < ai.ends_at
      AND (
          (cl.care_level = 'emergency' AND {$alias}.urgency = 1)
          OR (cl.care_level = 'inpatient' AND {$alias}.urgency = 2)
          OR (cl.care_level = 'outpatient' AND {$alias}.urgency = 3)
          OR (cl.care_level NOT IN ('emergency', 'inpatient', 'outpatient') AND {$alias}.urgency IN (1, 2, 3))
      )
)
SQL;
    }

    private function windowSql(): string
    {
        return $this->boundSql('from', 'to');
    }

    private function boundSql(string $from, string $to): string
    {
        return sprintf(
            "a.created_at >= (CAST(:%1\$s AS TIMESTAMPTZ) AT TIME ZONE 'Europe/Berlin') AND a.created_at < (CAST(:%2\$s AS TIMESTAMPTZ) AT TIME ZONE 'Europe/Berlin')",
            $from,
            $to,
        );
    }

    /**
     * @param list<string> $parts
     */
    private function sqlJoin(array $parts): string
    {
        return implode(",\n    ", $parts);
    }

    /**
     * @param array<string, int|string|bool|null> $row
     */
    private function row(array $row): ClosureEventAllocationRow
    {
        $urgency = AllocationUrgency::tryFrom((int) $row['urgency']);
        if (!$urgency instanceof AllocationUrgency) {
            $urgency = AllocationUrgency::INPATIENT;
        }
        $closed = $row['department_was_closed'];
        $isMember = $this->asBool($row['is_event_member_department'] ?? false);
        $closedAtAssignment = $this->asBool($row['closed_at_assignment_time'] ?? false);
        $context = match (true) {
            $closedAtAssignment && $isMember => ClosureEventAssignmentRowContext::EventMemberClosed,
            $isMember => ClosureEventAssignmentRowContext::EventMemberOpen,
            default => ClosureEventAssignmentRowContext::SpecialityOnly,
        };

        return new ClosureEventAllocationRow(
            (string) $row['public_id'],
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('Europe/Berlin')),
            (string) $row['speciality_name'],
            (string) $row['department_name'],
            $urgency,
            true === $closed || 1 === $closed || '1' === $closed || 't' === $closed || 'true' === $closed,
            $isMember,
            $closedAtAssignment,
            $context,
        );
    }

    private function asBool(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value || 'true' === $value;
    }
}
