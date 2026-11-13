<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\ValidationStratum;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Populations of the match validation strata of one hospital and period (#1057), each in a reproducible
 * random order: rows are ordered by md5(seed, stratum, id), so the same seed on the same data draws the
 * same sample. Period rules as in the match review: allocations by IVENA arrival, visits by matching
 * anchor (ED admission, else first assessment contact), whole days on the stored local wall clock.
 *
 * - automatic links: `auto_accepted` pairs of the period's allocations
 * - proposals: `proposed` pairs of the period's allocations (open by construction: proposals touching a
 *   link are removed)
 * - unlinked allocations: no effective link and no open proposal
 * - unlinked visits: EMS or unknown arrival route, no effective link and no open proposal
 */
final readonly class ValidationSampleQuery
{
    private const string VISIT_ANCHOR = 'COALESCE(e.ed_admission_at, e.first_assessment_contact_at)';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Automatic links or proposals of the period's allocations in sample order.
     *
     * @return list<array{allocation_id: int, encounter_id: int, status: MatchStatus, score: float, points: ?int, pass: ?int, matcher_version: ?string}>
     */
    public function pairs(int $hospitalId, ReviewPeriod $period, ValidationStratum $stratum, int $seed): array
    {
        $status = match ($stratum) {
            ValidationStratum::Automatic => MatchStatus::AutoAccepted,
            ValidationStratum::Proposal => MatchStatus::Proposed,
            default => throw new \InvalidArgumentException('Only pair strata have pairs.'),
        };

        /** @var list<array{allocation_id: int|string, encounter_id: int|string, status: string, score: float|string|null, points: int|string|null, pass: int|string|null, matcher_version: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT m.allocation_id, m.encounter_id, m.status, m.score, m.points, m.pass, m.matcher_version
FROM encounter_match m
JOIN allocation a ON a.id = m.allocation_id
WHERE m.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :to AND m.status = :status
ORDER BY md5(CONCAT(CAST(:seed AS TEXT), '|', CAST(:stratum AS TEXT), '|', m.allocation_id, '|', m.encounter_id)), m.id
SQL,
            [...$this->period($hospitalId, $period), 'status' => $status->value, 'seed' => $seed, 'stratum' => $stratum->value],
            [...$this->periodTypes(), 'status' => Types::STRING, 'seed' => Types::INTEGER, 'stratum' => Types::STRING],
        );

        return array_map(static fn (array $row): array => [
            'allocation_id' => (int) $row['allocation_id'],
            'encounter_id' => (int) $row['encounter_id'],
            'status' => MatchStatus::from($row['status']),
            'score' => (float) ($row['score'] ?? 0.0),
            'points' => null === $row['points'] ? null : (int) $row['points'],
            'pass' => null === $row['pass'] ? null : (int) $row['pass'],
            'matcher_version' => $row['matcher_version'],
        ], $rows);
    }

    /**
     * Allocations of the period without effective link and without open proposal, in sample order.
     *
     * @return list<int>
     */
    public function unlinkedAllocations(int $hospitalId, ReviewPeriod $period, int $seed): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT a.id
FROM allocation a
WHERE a.hospital_id = :hospital AND a.arrival_at >= :from AND a.arrival_at < :to
  AND NOT EXISTS (SELECT 1 FROM encounter_match m WHERE m.allocation_id = a.id AND m.status IN ('auto_accepted', 'confirmed', 'proposed'))
ORDER BY md5(CONCAT(CAST(:seed AS TEXT), '|unlinked_allocation|', a.id)), a.id
SQL,
            [...$this->period($hospitalId, $period), 'seed' => $seed],
            [...$this->periodTypes(), 'seed' => Types::INTEGER],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * Visits of the period with EMS or unknown arrival route, without effective link and without open
     * proposal, in sample order.
     *
     * @return list<int>
     */
    public function unlinkedVisits(int $hospitalId, ReviewPeriod $period, int $seed): array
    {
        $anchor = self::VISIT_ANCHOR;
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            <<<SQL
SELECT e.id
FROM encounter e
WHERE e.hospital_id = :hospital
  AND e.anchor_at BETWEEN CAST(:from AS TIMESTAMP) - INTERVAL '1 day' AND CAST(:to AS TIMESTAMP) + INTERVAL '1 day'
  AND {$anchor} >= :from AND {$anchor} < :to
  AND e.arrival_route IN ('ems', 'unknown')
  AND NOT EXISTS (SELECT 1 FROM encounter_match m WHERE m.encounter_id = e.id AND m.status IN ('auto_accepted', 'confirmed', 'proposed'))
ORDER BY md5(CONCAT(CAST(:seed AS TEXT), '|unlinked_visit|', e.id)), e.id
SQL,
            [...$this->period($hospitalId, $period), 'seed' => $seed],
            [...$this->periodTypes(), 'seed' => Types::INTEGER],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * @return array{hospital: int, from: \DateTimeImmutable, to: \DateTimeImmutable}
     */
    private function period(int $hospitalId, ReviewPeriod $period): array
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
