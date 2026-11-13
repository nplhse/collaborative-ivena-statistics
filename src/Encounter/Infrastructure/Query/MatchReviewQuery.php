<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Review\ReviewContext;
use App\Encounter\Application\Review\ReviewFilter;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MatchStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Read model of the match review (ADR 030, #1054): counts with their denominators and the review lists
 * of one hospital and period.
 *
 * Population: allocations of the hospital with IVENA arrival in the period, visits of the hospital with
 * their matching anchor (ED admission, else first assessment contact) in the period. Each allocation
 * falls into exactly one of: automatic link, confirmed link, open proposal, no proposal. A proposal is in
 * open conflict while another allocation's conflict proposal for the same visit is still open.
 */
final readonly class MatchReviewQuery implements MatchReviewReaderInterface
{
    private const string VISIT_ANCHOR = 'COALESCE(e.ed_admission_at, e.first_assessment_contact_at)';

    private const string OPEN_CONFLICT = <<<'SQL'
(m.status = 'proposed' AND m.conflict AND EXISTS (
    SELECT 1 FROM encounter_match rival
    WHERE rival.encounter_id = m.encounter_id AND rival.allocation_id <> m.allocation_id
      AND rival.status = 'proposed' AND rival.conflict
))
SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Day of the latest visit of the hospital, to open the review on a period with data.
     */
    #[\Override]
    public function latestVisitDay(int $hospitalId): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT MAX(anchor_at) FROM encounter WHERE hospital_id = ?', [$hospitalId], [Types::INTEGER]);

        return \is_string($value) ? new \DateTimeImmutable(substr($value, 0, 10)) : null;
    }

    /**
     * Hospitals with encounter data, for administrators who hold REVIEW implicitly.
     *
     * @return list<array{id: int, name: string}>
     */
    #[\Override]
    public function hospitalsWithEncounters(): array
    {
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT h.id, h.name FROM hospital h WHERE EXISTS (SELECT 1 FROM encounter e WHERE e.hospital_id = h.id) ORDER BY h.name, h.id',
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name']], $rows);
    }

    /**
     * @return array{
     *     allocations: int, automatic: int, confirmed: int, proposed: int, conflicts: int, no_proposal: int, rejected: int,
     *     visits: array<string, array{total: int, linked: int, unlinked: int}>, visits_total: int, visits_linked: int
     * }
     */
    #[\Override]
    public function summary(int $hospitalId, ReviewPeriod $period): array
    {
        /** @var array{allocations: int|string, automatic: int|string, confirmed: int|string, proposed: int|string, conflicts: int|string, no_proposal: int|string} $allocations */
        $allocations = $this->connection->fetchAssociative(
            <<<SQL
WITH a AS (
    SELECT
        EXISTS (SELECT 1 FROM encounter_match m WHERE m.allocation_id = a.id AND m.status = 'auto_accepted') AS automatic,
        EXISTS (SELECT 1 FROM encounter_match m WHERE m.allocation_id = a.id AND m.status = 'confirmed') AS confirmed,
        EXISTS (SELECT 1 FROM encounter_match m WHERE m.allocation_id = a.id AND m.status = 'proposed') AS proposed,
        EXISTS (SELECT 1 FROM encounter_match m WHERE m.allocation_id = a.id AND {$this->openConflict()}) AS conflict
    FROM allocation a
    WHERE a.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :to
)
SELECT
    COUNT(*) AS allocations,
    COUNT(*) FILTER (WHERE automatic) AS automatic,
    COUNT(*) FILTER (WHERE confirmed) AS confirmed,
    COUNT(*) FILTER (WHERE NOT automatic AND NOT confirmed AND proposed) AS proposed,
    COUNT(*) FILTER (WHERE NOT automatic AND NOT confirmed AND conflict) AS conflicts,
    COUNT(*) FILTER (WHERE NOT automatic AND NOT confirmed AND NOT proposed) AS no_proposal
FROM a
SQL,
            $this->periodParameters($hospitalId, $period),
            $this->periodTypes(),
        );

        $rejected = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM encounter_match m JOIN allocation a ON a.id = m.allocation_id WHERE m.hospital_id = :hospital AND m.status = 'rejected' AND a.arrival_at >= :from AND a.arrival_at < :to",
            $this->periodParameters($hospitalId, $period),
            $this->periodTypes(),
        );

        /** @var list<array{arrival_route: string, total: int|string, linked: int|string}> $visitRows */
        $visitRows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT e.arrival_route, COUNT(*) AS total, COUNT(*) FILTER (WHERE {$this->visitLinked()}) AS linked
FROM encounter e
WHERE {$this->visitInPeriod()}
GROUP BY e.arrival_route
SQL,
            $this->periodParameters($hospitalId, $period),
            $this->periodTypes(),
        );

        $visits = [];
        foreach (ArrivalRoute::cases() as $route) {
            $visits[$route->value] = ['total' => 0, 'linked' => 0, 'unlinked' => 0];
        }
        foreach ($visitRows as $row) {
            $visits[$row['arrival_route']] = ['total' => (int) $row['total'], 'linked' => (int) $row['linked'], 'unlinked' => (int) $row['total'] - (int) $row['linked']];
        }

        return [
            'allocations' => (int) $allocations['allocations'],
            'automatic' => (int) $allocations['automatic'],
            'confirmed' => (int) $allocations['confirmed'],
            'proposed' => (int) $allocations['proposed'],
            'conflicts' => (int) $allocations['conflicts'],
            'no_proposal' => (int) $allocations['no_proposal'],
            'rejected' => $rejected,
            'visits' => $visits,
            'visits_total' => array_sum(array_column($visits, 'total')),
            'visits_linked' => array_sum(array_column($visits, 'linked')),
        ];
    }

    /**
     * Decided pairs of the "done" segment (automatic or confirmed links, rejected pairs, or one of them) in the
     * context's order.
     *
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function donePairs(int $hospitalId, ReviewContext $context, int $offset, int $limit): array
    {
        return array_map($this->pairRow(...), $this->connection->fetchAllAssociative(
            <<<SQL
SELECT m.id, m.status, m.conflict, false AS open_conflict, m.score, m.points, m.pass, m.time_anchor,
       m.time_difference_minutes, m.decided_at, u.username AS decided_by,
       a.id AS allocation_id, a.arrival_at, a.gender, a.age AS allocation_age,
       e.id AS encounter_id, {$this->anchor()} AS anchor_at, e.sex, e.age AS encounter_age, e.arrival_route
FROM encounter_match m
JOIN allocation a ON a.id = m.allocation_id
JOIN encounter e ON e.id = m.encounter_id
LEFT JOIN "user" u ON u.id = m.decided_by_id
WHERE {$this->doneCondition()}
ORDER BY {$this->pairOrder($context)}
LIMIT :limit OFFSET :offset
SQL,
            [...$this->doneParameters($hospitalId, $context), 'limit' => $limit, 'offset' => $offset],
            [...$this->doneTypes(), 'limit' => Types::INTEGER, 'offset' => Types::INTEGER],
        ));
    }

    #[\Override]
    public function countDonePairs(int $hospitalId, ReviewContext $context): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM encounter_match m JOIN allocation a ON a.id = m.allocation_id WHERE {$this->doneCondition()}",
            $this->doneParameters($hospitalId, $context),
            $this->doneTypes(),
        );
    }

    /**
     * Allocation ids of "no proposal" or "done", each once, in the order of the list.
     *
     * @return list<int>
     */
    #[\Override]
    public function segmentAllocationIds(int $hospitalId, ReviewContext $context): array
    {
        $ids = ReviewFilter::NoProposal === $context->filter
            ? $this->connection->fetchFirstColumn(
                "SELECT a.id FROM allocation a WHERE {$this->noProposalCondition()} ORDER BY a.arrival_at, a.id",
                $this->periodParameters($hospitalId, $context->period),
                $this->periodTypes(),
            )
            : $this->connection->fetchFirstColumn(
                "SELECT a.id FROM encounter_match m JOIN allocation a ON a.id = m.allocation_id WHERE {$this->doneCondition()} ORDER BY {$this->pairOrder($context)}",
                $this->doneParameters($hospitalId, $context),
                $this->doneTypes(),
            );

        return array_values(array_unique(array_map(intval(...), $ids)));
    }

    /**
     * One row per allocation of the "open" segment, by IVENA arrival: allocations without link that have an
     * open proposal (with their best one), and the given allocations with a swap proposal (with their current
     * automatic link).
     *
     * @param list<int> $swapAllocationIds
     *
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function openItems(int $hospitalId, ReviewPeriod $period, array $swapAllocationIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
WITH best AS (
    SELECT DISTINCT ON (m.allocation_id) m.*
    FROM encounter_match m
    JOIN allocation a ON a.id = m.allocation_id
    WHERE m.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :to
      AND (
          (m.status = 'proposed' AND NOT EXISTS (
              SELECT 1 FROM encounter_match l WHERE l.allocation_id = m.allocation_id AND l.status IN ('auto_accepted', 'confirmed')
          ))
          OR (m.status = 'auto_accepted' AND m.allocation_id = ANY(CAST(:swaps AS INT[])))
      )
    ORDER BY m.allocation_id, (m.status = 'auto_accepted') DESC, m.score DESC NULLS LAST, m.id
)
SELECT m.id, m.status, m.conflict, m.score, m.points, m.pass, m.time_anchor, m.time_difference_minutes,
       NULL AS decided_at, NULL AS decided_by,
       EXISTS (
           SELECT 1 FROM encounter_match m WHERE m.allocation_id = a.id AND {$this->openConflict()}
       ) AS open_conflict,
       (SELECT COUNT(*) FROM encounter_match p WHERE p.allocation_id = a.id AND p.status = 'proposed') AS proposals,
       a.id AS allocation_id, a.arrival_at, a.gender, a.age AS allocation_age,
       e.id AS encounter_id, {$this->anchor()} AS anchor_at, e.sex, e.age AS encounter_age, e.arrival_route
FROM best m
JOIN allocation a ON a.id = m.allocation_id
JOIN encounter e ON e.id = m.encounter_id
ORDER BY a.arrival_at, a.id
SQL,
            [...$this->periodParameters($hospitalId, $period), 'swaps' => '{'.implode(',', $swapAllocationIds).'}'],
            [...$this->periodTypes(), 'swaps' => Types::STRING],
        );

        $swaps = array_fill_keys($swapAllocationIds, true);

        return array_map(fn (array $row): array => [
            ...$this->pairRow($row),
            'proposals' => (int) $row['proposals'],
            'swap' => isset($swaps[(int) $row['allocation_id']]),
        ], $rows);
    }

    /**
     * Allocations without link and without proposal, by IVENA arrival.
     *
     * @return list<array{allocation_id: int, arrival_at: \DateTimeImmutable, gender: string, age: int, with_physician: bool, work_accident: bool}>
     */
    #[\Override]
    public function allocationsWithoutProposal(int $hospitalId, ReviewPeriod $period, int $offset, int $limit): array
    {
        /** @var list<array{id: int|string, arrival_at: string, gender: string, age: int|string, is_with_physician: bool, is_work_accident: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT a.id, a.arrival_at, a.gender, a.age, a.is_with_physician, a.is_work_accident
FROM allocation a
WHERE {$this->noProposalCondition()}
ORDER BY a.arrival_at, a.id
LIMIT :limit OFFSET :offset
SQL,
            [...$this->periodParameters($hospitalId, $period), 'limit' => $limit, 'offset' => $offset],
            [...$this->periodTypes(), 'limit' => Types::INTEGER, 'offset' => Types::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'allocation_id' => (int) $row['id'],
            'arrival_at' => new \DateTimeImmutable($row['arrival_at']),
            'gender' => $row['gender'],
            'age' => (int) $row['age'],
            'with_physician' => (bool) $row['is_with_physician'],
            'work_accident' => (bool) $row['is_work_accident'],
        ], $rows);
    }

    #[\Override]
    public function countAllocationsWithoutProposal(int $hospitalId, ReviewPeriod $period): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM allocation a WHERE {$this->noProposalCondition()}",
            $this->periodParameters($hospitalId, $period),
            $this->periodTypes(),
        );
    }

    /**
     * Visits without effective link, by matching anchor; with the allocation of their best open proposal.
     *
     * @return list<array{encounter_id: int, anchor_at: \DateTimeImmutable, substitute: bool, sex: string, age: int, arrival_route: string, arrival_mode: ?string, proposal_allocation_id: ?int}>
     */
    #[\Override]
    public function unlinkedVisits(int $hospitalId, ReviewPeriod $period, ?ArrivalRoute $route, int $offset, int $limit): array
    {
        /** @var list<array{id: int|string, anchor_at: string, substitute: bool, sex: string, age: int|string, arrival_route: string, arrival_mode: ?string, proposal_allocation_id: int|string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
SELECT e.id, {$this->anchor()} AS anchor_at, e.ed_admission_at IS NULL AS substitute, e.sex, e.age, e.arrival_route, e.arrival_mode,
       (SELECT m.allocation_id FROM encounter_match m WHERE m.encounter_id = e.id AND m.status = 'proposed'
        ORDER BY m.score DESC, m.allocation_id LIMIT 1) AS proposal_allocation_id
FROM encounter e
WHERE {$this->unlinkedVisitCondition()}
ORDER BY {$this->anchor()}, e.id
LIMIT :limit OFFSET :offset
SQL,
            [...$this->periodParameters($hospitalId, $period), 'route' => $route?->value, 'limit' => $limit, 'offset' => $offset],
            [...$this->periodTypes(), 'route' => Types::STRING, 'limit' => Types::INTEGER, 'offset' => Types::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'encounter_id' => (int) $row['id'],
            'anchor_at' => new \DateTimeImmutable($row['anchor_at']),
            'substitute' => (bool) $row['substitute'],
            'sex' => $row['sex'],
            'age' => (int) $row['age'],
            'arrival_route' => $row['arrival_route'],
            'arrival_mode' => $row['arrival_mode'],
            'proposal_allocation_id' => null === $row['proposal_allocation_id'] ? null : (int) $row['proposal_allocation_id'],
        ], $rows);
    }

    #[\Override]
    public function countUnlinkedVisits(int $hospitalId, ReviewPeriod $period, ?ArrivalRoute $route): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM encounter e WHERE {$this->unlinkedVisitCondition()}",
            [...$this->periodParameters($hospitalId, $period), 'route' => $route?->value],
            [...$this->periodTypes(), 'route' => Types::STRING],
        );
    }

    /**
     * The allocation of the review page, or null when it does not belong to the hospital.
     *
     * @return array{id: int, public_id: string, arrival_at: \DateTimeImmutable, gender: string, age: int, with_physician: bool, work_accident: bool}|null
     */
    #[\Override]
    public function allocation(int $hospitalId, int $allocationId): ?array
    {
        /** @var array{id: int|string, public_id: string, arrival_at: string, gender: string, age: int|string, is_with_physician: bool, is_work_accident: bool}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, public_id, arrival_at, gender, age, is_with_physician, is_work_accident FROM allocation WHERE id = :id AND hospital_id = :hospital',
            ['id' => $allocationId, 'hospital' => $hospitalId],
            ['id' => Types::INTEGER, 'hospital' => Types::INTEGER],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'public_id' => $row['public_id'],
            'arrival_at' => new \DateTimeImmutable($row['arrival_at']),
            'gender' => $row['gender'],
            'age' => (int) $row['age'],
            'with_physician' => (bool) $row['is_with_physician'],
            'work_accident' => (bool) $row['is_work_accident'],
        ];
    }

    /**
     * Optional context for unclear cases (#1054): IVENA urgency and normalized indication of the allocation,
     * MTS category, diagram and indicator of the visits. None of these is a matching feature (ADR 030).
     *
     * @param list<int> $encounterIds
     *
     * @return array{allocation: array{urgency: ?int, indication_code: ?int, indication_name: ?string}, encounters: array<int, array{category: ?string, diagram: ?string, indicator: ?string}>}
     */
    #[\Override]
    public function extendedInfo(int $allocationId, array $encounterIds): array
    {
        /** @var array{urgency: int|string|null, indication_code: int|string|null, indication_name: ?string}|false $allocation */
        $allocation = $this->connection->fetchAssociative(
            'SELECT a.urgency, n.code AS indication_code, n.name AS indication_name FROM allocation a LEFT JOIN indication_normalized n ON n.id = a.indication_normalized_id WHERE a.id = :id',
            ['id' => $allocationId],
            ['id' => Types::INTEGER],
        );

        /** @var list<array{id: int|string, mts_category: ?string, mts_diagram: ?string, mts_indicator: ?string}> $rows */
        $rows = [] === $encounterIds ? [] : $this->connection->fetchAllAssociative(
            'SELECT id, mts_category, mts_diagram, mts_indicator FROM encounter WHERE id = ANY(CAST(:ids AS INT[]))',
            ['ids' => '{'.implode(',', $encounterIds).'}'],
            ['ids' => Types::STRING],
        );

        $encounters = [];
        foreach ($rows as $row) {
            $encounters[(int) $row['id']] = ['category' => $row['mts_category'], 'diagram' => $row['mts_diagram'], 'indicator' => $row['mts_indicator']];
        }

        return [
            'allocation' => [
                'urgency' => false === $allocation || null === $allocation['urgency'] ? null : (int) $allocation['urgency'],
                'indication_code' => false === $allocation || null === $allocation['indication_code'] ? null : (int) $allocation['indication_code'],
                'indication_name' => false === $allocation ? null : $allocation['indication_name'],
            ],
            'encounters' => $encounters,
        ];
    }

    /**
     * Stored rows touching the given visits: the allocation's own pairs and the visits' links elsewhere.
     *
     * @param list<int> $encounterIds
     *
     * @return list<array{id: int, allocation_id: int, encounter_id: int, status: MatchStatus, conflict: bool, score: ?float, points: ?int, pass: ?int, contributions: array<string, int>, decided_by: ?string, decided_at: ?\DateTimeImmutable}>
     */
    #[\Override]
    public function rowsTouching(int $allocationId, array $encounterIds): array
    {
        /** @var list<array{id: int|string, allocation_id: int|string, encounter_id: int|string, status: string, conflict: bool, score: float|string|null, points: int|string|null, pass: int|string|null, contributions: string, decided_by: ?string, decided_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT m.id, m.allocation_id, m.encounter_id, m.status, m.conflict, m.score, m.points, m.pass, m.contributions, u.username AS decided_by, m.decided_at
FROM encounter_match m
LEFT JOIN "user" u ON u.id = m.decided_by_id
WHERE m.allocation_id = :allocation OR m.encounter_id = ANY(CAST(:encounters AS INT[]))
ORDER BY m.id
SQL,
            ['allocation' => $allocationId, 'encounters' => '{'.implode(',', $encounterIds).'}'],
            ['allocation' => Types::INTEGER, 'encounters' => Types::STRING],
        );

        return array_map(static function (array $row): array {
            /** @var array<string, int> $contributions */
            $contributions = json_decode($row['contributions'], true, 4, \JSON_THROW_ON_ERROR);

            return [
                'id' => (int) $row['id'],
                'allocation_id' => (int) $row['allocation_id'],
                'encounter_id' => (int) $row['encounter_id'],
                'status' => MatchStatus::from($row['status']),
                'conflict' => (bool) $row['conflict'],
                'score' => null === $row['score'] ? null : (float) $row['score'],
                'points' => null === $row['points'] ? null : (int) $row['points'],
                'pass' => null === $row['pass'] ? null : (int) $row['pass'],
                'contributions' => $contributions,
                'decided_by' => $row['decided_by'],
                'decided_at' => null === $row['decided_at'] ? null : new \DateTimeImmutable($row['decided_at']),
            ];
        }, $rows);
    }

    /**
     * Effective links and rejected pairs of the hospital whose allocation lies in [from, to), as input of
     * the swap planner.
     *
     * @return array{automaticLinks: array<int, int>, linkedAllocations: array<int, true>, linkedEncounters: array<int, true>, rejectedPairs: array<string, true>}
     */
    public function linkState(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{allocation_id: int|string, encounter_id: int|string, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT m.allocation_id, m.encounter_id, m.status
FROM encounter_match m
WHERE m.hospital_id = :hospital AND m.status <> 'proposed'
  AND (
      m.allocation_id IN (SELECT a.id FROM allocation a WHERE a.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :to)
      OR m.encounter_id IN (
          SELECT e.id FROM encounter e
          WHERE e.hospital_id = :hospital
            AND e.anchor_at BETWEEN CAST(:from AS TIMESTAMP) - INTERVAL '1 day' AND CAST(:to AS TIMESTAMP) + INTERVAL '1 day'
            AND COALESCE(e.ed_admission_at, e.first_assessment_contact_at) >= :from
            AND COALESCE(e.ed_admission_at, e.first_assessment_contact_at) < :to
      )
  )
SQL,
            ['hospital' => $hospitalId, 'from' => $from, 'to' => $to],
            ['hospital' => Types::INTEGER, 'from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        );

        $state = ['automaticLinks' => [], 'linkedAllocations' => [], 'linkedEncounters' => [], 'rejectedPairs' => []];
        foreach ($rows as $row) {
            $allocationId = (int) $row['allocation_id'];
            $encounterId = (int) $row['encounter_id'];
            if (MatchStatus::Rejected->value === $row['status']) {
                $state['rejectedPairs'][$allocationId.':'.$encounterId] = true;

                continue;
            }

            if (MatchStatus::AutoAccepted->value === $row['status']) {
                $state['automaticLinks'][$allocationId] = $encounterId;
            }
            $state['linkedAllocations'][$allocationId] = true;
            $state['linkedEncounters'][$encounterId] = true;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function pairRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'status' => MatchStatus::from((string) $row['status']),
            'conflict' => (bool) $row['conflict'],
            'open_conflict' => (bool) $row['open_conflict'],
            'score' => null === $row['score'] ? null : (float) $row['score'],
            'points' => null === $row['points'] ? null : (int) $row['points'],
            'pass' => null === $row['pass'] ? null : (int) $row['pass'],
            'substitute' => 'assessment' === $row['time_anchor'],
            'time_difference_minutes' => (int) $row['time_difference_minutes'],
            'decided_at' => \is_string($row['decided_at']) ? new \DateTimeImmutable($row['decided_at']) : null,
            'decided_by' => \is_string($row['decided_by']) ? $row['decided_by'] : null,
            'allocation_id' => (int) $row['allocation_id'],
            'arrival_at' => new \DateTimeImmutable((string) $row['arrival_at']),
            'gender' => (string) $row['gender'],
            'allocation_age' => (int) $row['allocation_age'],
            'encounter_id' => (int) $row['encounter_id'],
            'anchor_at' => new \DateTimeImmutable((string) $row['anchor_at']),
            'sex' => (string) $row['sex'],
            'encounter_age' => (int) $row['encounter_age'],
            'arrival_route' => (string) $row['arrival_route'],
        ];
    }

    private function doneCondition(): string
    {
        return 'm.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :to AND m.status = ANY(CAST(:statuses AS TEXT[]))';
    }

    /**
     * @return array{hospital: int, from: \DateTimeImmutable, to: \DateTimeImmutable, statuses: string}
     */
    private function doneParameters(int $hospitalId, ReviewContext $context): array
    {
        $statuses = $context->status instanceof MatchStatus
            ? [$context->status->value]
            : [MatchStatus::AutoAccepted->value, MatchStatus::Confirmed->value, MatchStatus::Rejected->value];

        return [...$this->periodParameters($hospitalId, $context->period), 'statuses' => '{'.implode(',', $statuses).'}'];
    }

    /**
     * @return array{hospital: string, from: string, to: string, statuses: string}
     */
    private function doneTypes(): array
    {
        return [...$this->periodTypes(), 'statuses' => Types::STRING];
    }

    private function pairOrder(ReviewContext $context): string
    {
        $direction = 'desc' === $context->orderBy ? 'DESC' : 'ASC';

        return match ($context->sortBy) {
            'agreement' => "m.score {$direction} NULLS LAST, a.arrival_at, a.id, m.id",
            'difference' => "m.time_difference_minutes {$direction}, a.arrival_at, a.id, m.id",
            default => "a.arrival_at {$direction}, a.id {$direction}, m.score DESC NULLS LAST, m.id",
        };
    }

    private function noProposalCondition(): string
    {
        return <<<'SQL'
a.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :to
AND NOT EXISTS (SELECT 1 FROM encounter_match m WHERE m.allocation_id = a.id AND m.status IN ('auto_accepted', 'confirmed', 'proposed'))
SQL;
    }

    private function unlinkedVisitCondition(): string
    {
        return $this->visitInPeriod().' AND NOT '.$this->visitLinked().' AND (CAST(:route AS VARCHAR) IS NULL OR e.arrival_route = :route)';
    }

    private function visitInPeriod(): string
    {
        // The fingerprint anchor (indexed) lies at most a day away from the matching anchor.
        return sprintf(
            "e.hospital_id = :hospital AND e.anchor_at BETWEEN CAST(:from AS TIMESTAMP) - INTERVAL '1 day' AND CAST(:to AS TIMESTAMP) + INTERVAL '1 day' AND %1\$s >= :from AND %1\$s < :to",
            self::VISIT_ANCHOR,
        );
    }

    private function visitLinked(): string
    {
        return "EXISTS (SELECT 1 FROM encounter_match l WHERE l.encounter_id = e.id AND l.status IN ('auto_accepted', 'confirmed'))";
    }

    private function anchor(): string
    {
        return self::VISIT_ANCHOR;
    }

    private function openConflict(): string
    {
        return self::OPEN_CONFLICT;
    }

    /**
     * @return array{hospital: int, from: \DateTimeImmutable, to: \DateTimeImmutable}
     */
    private function periodParameters(int $hospitalId, ReviewPeriod $period): array
    {
        return ['hospital' => $hospitalId, 'from' => $period->from, 'to' => $period->toExclusive()];
    }

    /**
     * @return array{hospital: string, from: string, to: string}
     */
    private function periodTypes(): array
    {
        return ['hospital' => Types::INTEGER, 'from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE];
    }
}
