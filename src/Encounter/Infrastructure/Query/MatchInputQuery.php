<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\MatchCandidateReaderInterface;
use App\Encounter\Application\Matching\MatchingAllocation;
use App\Encounter\Application\Matching\MatchingEncounter;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Reads what the matcher compares (ADR 029): allocations and visits of one hospital and the existing
 * links and decisions that touch them.
 */
final readonly class MatchInputQuery implements MatchCandidateReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Allocations with IVENA arrival in [from, to).
     *
     * @return list<MatchingAllocation>
     */
    public function allocations(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $ignoreImportId): array
    {
        /** @var list<array{id: int|string, arrival_at: string, gender: string, age: int|string, is_with_physician: bool, is_work_accident: bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT id, arrival_at, gender, age, is_with_physician, is_work_accident
FROM allocation
WHERE hospital_id = :hospital
  AND arrival_at >= :from AND arrival_at < :to
  AND import_id <> :ignore
ORDER BY id
SQL,
            ['hospital' => $hospitalId, 'from' => $from, 'to' => $to, 'ignore' => $ignoreImportId ?? 0],
            ['hospital' => Types::INTEGER, 'from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE, 'ignore' => Types::INTEGER],
        );

        return array_map(static fn (array $row): MatchingAllocation => new MatchingAllocation(
            id: (int) $row['id'],
            arrivalAt: new \DateTimeImmutable($row['arrival_at']),
            sex: $row['gender'],
            age: (int) $row['age'],
            withPhysician: (bool) $row['is_with_physician'],
            workAccident: (bool) $row['is_work_accident'],
        ), $rows);
    }

    /**
     * One allocation of the hospital, or null (match review, #1054).
     */
    #[\Override]
    public function allocation(int $hospitalId, int $allocationId): ?MatchingAllocation
    {
        $arrivalAt = $this->connection->fetchOne(
            'SELECT arrival_at FROM allocation WHERE id = :id AND hospital_id = :hospital',
            ['id' => $allocationId, 'hospital' => $hospitalId],
            ['id' => Types::INTEGER, 'hospital' => Types::INTEGER],
        );
        if (!\is_string($arrivalAt)) {
            return null;
        }

        $from = new \DateTimeImmutable($arrivalAt);
        foreach ($this->allocations($hospitalId, $from, $from->modify('+1 second'), null) as $allocation) {
            if ($allocation->id === $allocationId) {
                return $allocation;
            }
        }

        return null;
    }

    /**
     * Visits whose matching anchor (ED admission, else first assessment contact) lies in [from, to].
     *
     * @return list<MatchingEncounter>
     */
    #[\Override]
    public function encounters(int $hospitalId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $ignoreImportId): array
    {
        // The fingerprint anchor (anchor_at, indexed) is the first assessment contact when there is one;
        // the ED admission lies before it, so a margin of one day keeps the index useful.
        /** @var list<array{id: int|string, ed_admission_at: ?string, first_assessment_contact_at: ?string, sex: string, age: int|string, arrival_mode: ?string, arrival_route: string, work_accident: ?bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT id, ed_admission_at, first_assessment_contact_at, sex, age, arrival_mode, arrival_route, work_accident
FROM encounter
WHERE hospital_id = :hospital
  AND anchor_at BETWEEN CAST(:from AS TIMESTAMP) - INTERVAL '1 day' AND CAST(:to AS TIMESTAMP) + INTERVAL '1 day'
  AND COALESCE(ed_admission_at, first_assessment_contact_at) BETWEEN :from AND :to
  AND import_id <> :ignore
ORDER BY id
SQL,
            ['hospital' => $hospitalId, 'from' => $from, 'to' => $to, 'ignore' => $ignoreImportId ?? 0],
            ['hospital' => Types::INTEGER, 'from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE, 'ignore' => Types::INTEGER],
        );

        $encounters = [];
        foreach ($rows as $row) {
            $anchor = MatchingEncounter::anchorOf(
                null === $row['ed_admission_at'] ? null : new \DateTimeImmutable($row['ed_admission_at']),
                null === $row['first_assessment_contact_at'] ? null : new \DateTimeImmutable($row['first_assessment_contact_at']),
            );
            if (null === $anchor) {
                continue;
            }

            $encounters[] = new MatchingEncounter(
                id: (int) $row['id'],
                anchorAt: $anchor[0],
                anchorSource: $anchor[1],
                sex: Sex::from($row['sex']),
                age: (int) $row['age'],
                arrivalMode: null === $row['arrival_mode'] ? null : ArrivalMode::from($row['arrival_mode']),
                arrivalRoute: ArrivalRoute::from($row['arrival_route']),
                workAccident: $row['work_accident'],
            );
        }

        return $encounters;
    }

    /**
     * Effective links (both sides reserved) and rejected pairs touching the given allocations or visits.
     *
     * @param list<int> $allocationIds
     * @param list<int> $encounterIds
     *
     * @return array{reservedAllocations: array<int, true>, reservedEncounters: array<int, true>, rejectedPairs: array<string, true>}
     */
    public function existingDecisions(array $allocationIds, array $encounterIds): array
    {
        $state = ['reservedAllocations' => [], 'reservedEncounters' => [], 'rejectedPairs' => []];
        if ([] === $allocationIds && [] === $encounterIds) {
            return $state;
        }

        /** @var list<array{allocation_id: int|string, encounter_id: int|string, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT allocation_id, encounter_id, status FROM encounter_match WHERE status IN (:statuses) AND (allocation_id IN (:allocations) OR encounter_id IN (:encounters))',
            [
                'statuses' => [MatchStatus::AutoAccepted->value, MatchStatus::Confirmed->value, MatchStatus::Rejected->value],
                'allocations' => [] === $allocationIds ? [0] : $allocationIds,
                'encounters' => [] === $encounterIds ? [0] : $encounterIds,
            ],
            ['statuses' => ArrayParameterType::STRING, 'allocations' => ArrayParameterType::INTEGER, 'encounters' => ArrayParameterType::INTEGER],
        );

        foreach ($rows as $row) {
            $allocationId = (int) $row['allocation_id'];
            $encounterId = (int) $row['encounter_id'];
            if (MatchStatus::Rejected->value === $row['status']) {
                $state['rejectedPairs'][$allocationId.':'.$encounterId] = true;

                continue;
            }

            $state['reservedAllocations'][$allocationId] = true;
            $state['reservedEncounters'][$encounterId] = true;
        }

        return $state;
    }
}
