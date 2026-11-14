<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\EncounterOverviewReaderInterface;
use App\Encounter\Application\Overview\EncounterCoverage;
use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Overview\ProcessInterval;
use App\Encounter\Application\Overview\ProcessTimeDistribution;
use App\Encounter\Application\Overview\ProcessTimeStatistics;
use App\Encounter\Application\Overview\TimeGrain;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Descriptive aggregates of the encounter overview (#1055), see EncounterOverviewReaderInterface.
 * Always filtered on one hospital; the period applies to `anchor_at` (index `idx_encounter_hospital_anchor`).
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterOverviewReaderInterface.
 */
final readonly class EncounterOverviewQuery implements EncounterOverviewReaderInterface
{
    /** Start and end column of each process interval. */
    private const array INTERVAL_COLUMNS = [
        'admission_to_assessment' => ['ed_admission_at', 'first_assessment_contact_at'],
        'admission_to_physician' => ['ed_admission_at', 'first_physician_at'],
        'admission_to_discharge' => ['ed_admission_at', 'ed_discharge_at'],
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function coverage(int $hospitalId, EncounterPeriod $period): EncounterCoverage
    {
        [$where, $parameters] = $this->where($hospitalId, $period);
        /** @var array{visits: int|string, imports: int|string, days: int|string, first_anchor: ?string, last_anchor: ?string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS visits, COUNT(DISTINCT e.import_id) AS imports, COUNT(DISTINCT CAST(e.anchor_at AS date)) AS days,'
            .' MIN(e.anchor_at) AS first_anchor, MAX(e.anchor_at) AS last_anchor FROM encounter e WHERE '.$where,
            $parameters,
        );

        if (false === $row) {
            return new EncounterCoverage(0, 0, 0, null, null);
        }

        return new EncounterCoverage(
            (int) $row['visits'],
            (int) $row['imports'],
            (int) $row['days'],
            null === $row['first_anchor'] ? null : new \DateTimeImmutable($row['first_anchor']),
            null === $row['last_anchor'] ? null : new \DateTimeImmutable($row['last_anchor']),
        );
    }

    #[\Override]
    public function visitsByRouteAndLink(int $hospitalId, EncounterPeriod $period): array
    {
        [$where, $parameters, $types] = $this->where($hospitalId, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.arrival_route AS route, COALESCE(m.status, :not_linked) AS link, COUNT(*) AS visits FROM encounter e'
            .' LEFT JOIN encounter_match m ON m.encounter_id = e.id AND m.status IN (:effective)'
            .' WHERE '.$where.' GROUP BY 1, 2 ORDER BY 1, 2',
            $parameters + [
                'not_linked' => self::NOT_LINKED,
                'effective' => array_map(static fn (MatchStatus $status): string => $status->value, MatchStatus::effective()),
            ],
            $types + ['effective' => ArrayParameterType::STRING],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['route']][(string) $row['link']] = (int) $row['visits'];
        }

        return $counts;
    }

    #[\Override]
    public function bonusDecidedLinksByRoute(int $hospitalId, EncounterPeriod $period): array
    {
        [$where, $parameters, $types] = $this->where($hospitalId, $period);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.arrival_route AS route, COUNT(*) AS visits FROM encounter e'
            .' JOIN encounter_match m ON m.encounter_id = e.id AND m.status = :automatic AND m.plausibility_decisive'
            .' WHERE '.$where.' GROUP BY 1 ORDER BY 1',
            $parameters + ['automatic' => MatchStatus::AutoAccepted->value],
            $types,
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['route']] = (int) $row['visits'];
        }

        return $counts;
    }

    #[\Override]
    public function visitsByCategory(int $hospitalId, EncounterPeriod $period): array
    {
        return $this->countBy('e.mts_category', $hospitalId, $period);
    }

    #[\Override]
    public function visitsByDiagram(int $hospitalId, EncounterPeriod $period): array
    {
        return $this->countBy('e.mts_diagram', $hospitalId, $period);
    }

    #[\Override]
    public function visitsByDisposition(int $hospitalId, EncounterPeriod $period): array
    {
        return $this->countBy('e.disposition_code', $hospitalId, $period);
    }

    #[\Override]
    public function visitsByCareLevel(int $hospitalId, EncounterPeriod $period): array
    {
        return $this->countBy('e.disposition_care_level', $hospitalId, $period, ' AND e.disposition_code IN (:with_care_level)', [
            'with_care_level' => [Disposition::Admitted->value, Disposition::TransferredExternal->value],
        ], ['with_care_level' => ArrayParameterType::STRING]);
    }

    #[\Override]
    public function processTime(int $hospitalId, EncounterPeriod $period, ProcessInterval $interval): ProcessTimeStatistics
    {
        [$start, $end] = self::INTERVAL_COLUMNS[$interval->value];
        [$where, $parameters, $types] = $this->where($hospitalId, $period);
        $valid = 'd BETWEEN 0 AND :max_minutes';

        /** @var array{valid: int|string, missing: int|string, negative: int|string, implausible: int|string, q1: float|string|null, median: float|string|null, q3: float|string|null}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT'
            .' COUNT(*) FILTER (WHERE '.$valid.') AS valid,'
            .' COUNT(*) FILTER (WHERE d IS NULL) AS missing,'
            .' COUNT(*) FILTER (WHERE d < 0) AS negative,'
            .' COUNT(*) FILTER (WHERE d > :max_minutes) AS implausible,'
            .' percentile_cont(0.25) WITHIN GROUP (ORDER BY d) FILTER (WHERE '.$valid.') AS q1,'
            .' percentile_cont(0.5) WITHIN GROUP (ORDER BY d) FILTER (WHERE '.$valid.') AS median,'
            .' percentile_cont(0.75) WITHIN GROUP (ORDER BY d) FILTER (WHERE '.$valid.') AS q3'
            .sprintf(' FROM (SELECT FLOOR(EXTRACT(EPOCH FROM (e.%2$s - e.%1$s)) / 60) AS d FROM encounter e WHERE %3$s) durations', $start, $end, $where),
            $parameters + ['max_minutes' => ProcessInterval::MAX_PLAUSIBLE_MINUTES],
            $types,
        );

        if (false === $row) {
            return new ProcessTimeStatistics($interval, 0, 0, 0, 0, null, null, null);
        }

        $float = static fn (float|string|null $value): ?float => null === $value ? null : (float) $value;

        return new ProcessTimeStatistics(
            $interval,
            (int) $row['valid'],
            (int) $row['missing'],
            (int) $row['negative'],
            (int) $row['implausible'],
            $float($row['q1']),
            $float($row['median']),
            $float($row['q3']),
        );
    }

    #[\Override]
    public function processTimeByCategory(int $hospitalId, EncounterPeriod $period, ProcessInterval $interval): array
    {
        [$start, $end] = self::INTERVAL_COLUMNS[$interval->value];
        [$where, $parameters, $types] = $this->where($hospitalId, $period);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT category, COUNT(*) AS valid,'
            .' percentile_cont(0.1) WITHIN GROUP (ORDER BY d) AS p10,'
            .' percentile_cont(0.25) WITHIN GROUP (ORDER BY d) AS q1,'
            .' percentile_cont(0.5) WITHIN GROUP (ORDER BY d) AS median,'
            .' percentile_cont(0.75) WITHIN GROUP (ORDER BY d) AS q3,'
            .' percentile_cont(0.9) WITHIN GROUP (ORDER BY d) AS p90'
            .sprintf(' FROM (SELECT COALESCE(e.mts_category, \'\') AS category, FLOOR(EXTRACT(EPOCH FROM (e.%2$s - e.%1$s)) / 60) AS d FROM encounter e WHERE %3$s) durations', $start, $end, $where)
            .' WHERE d BETWEEN 0 AND :max_minutes GROUP BY category ORDER BY category',
            $parameters + ['max_minutes' => ProcessInterval::MAX_PLAUSIBLE_MINUTES],
            $types,
        );

        $float = static fn (mixed $value): ?float => null === $value ? null : (float) $value;

        return array_map(static fn (array $row): ProcessTimeDistribution => new ProcessTimeDistribution(
            (string) $row['category'],
            (int) $row['valid'],
            $float($row['p10']),
            $float($row['q1']),
            $float($row['median']),
            $float($row['q3']),
            $float($row['p90']),
        ), $rows);
    }

    #[\Override]
    public function visitsByBucket(int $hospitalId, EncounterPeriod $period, TimeGrain $grain): array
    {
        [$where, $parameters, $types] = $this->where($hospitalId, $period);
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT CAST(date_trunc(\'%s\', e.anchor_at) AS date) AS bucket, e.arrival_route AS route, COUNT(*) AS visits FROM encounter e WHERE %s GROUP BY 1, 2 ORDER BY 1, 2', $grain->value, $where),
            $parameters,
            $types,
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['bucket']][(string) $row['route']] = (int) $row['visits'];
        }

        return $counts;
    }

    #[\Override]
    public function hospitalsWithVisits(): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT h.id, h.name FROM hospital h WHERE EXISTS (SELECT 1 FROM encounter e WHERE e.hospital_id = h.id) ORDER BY h.name, h.id',
        );

        $hospitals = [];
        foreach ($rows as $id => $name) {
            $hospitals[(int) $id] = (string) $name;
        }

        return $hospitals;
    }

    /**
     * @param array<string, mixed>              $extraParameters
     * @param array<string, ArrayParameterType> $extraTypes
     *
     * @return array<string, int>
     */
    private function countBy(string $column, int $hospitalId, EncounterPeriod $period, string $extraCondition = '', array $extraParameters = [], array $extraTypes = []): array
    {
        [$where, $parameters, $types] = $this->where($hospitalId, $period);
        $rows = $this->connection->fetchAllKeyValue(
            sprintf('SELECT COALESCE(%1$s, \'\') AS code, COUNT(*) AS visits FROM encounter e WHERE %2$s%3$s GROUP BY 1 ORDER BY 1', $column, $where, $extraCondition),
            $parameters + $extraParameters,
            $types + $extraTypes,
        );

        $counts = [];
        foreach ($rows as $code => $visits) {
            $counts[(string) $code] = (int) $visits;
        }

        return $counts;
    }

    /**
     * @return array{string, array<string, int|string>, array<string, ArrayParameterType>}
     */
    private function where(int $hospitalId, EncounterPeriod $period): array
    {
        $where = 'e.hospital_id = :hospital';
        $parameters = ['hospital' => $hospitalId];

        if ($period->from instanceof \DateTimeImmutable) {
            $where .= ' AND e.anchor_at >= :from';
            $parameters['from'] = $period->from->format('Y-m-d H:i:s');
        }

        $endExclusive = $period->endExclusive();
        if ($endExclusive instanceof \DateTimeImmutable) {
            $where .= ' AND e.anchor_at < :to_exclusive';
            $parameters['to_exclusive'] = $endExclusive->format('Y-m-d H:i:s');
        }

        return [$where, $parameters, []];
    }
}
