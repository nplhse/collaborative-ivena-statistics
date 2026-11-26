<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Assignment;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentRequest;
use App\Encounter\Contract\Assignment\AssignmentResult;
use App\Encounter\Contract\Assignment\CandidateReason;
use App\Encounter\Contract\Assignment\EncounterAssignmentsInterface;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Domain\Entity\EncounterPendingAssignment;
use App\Import\Domain\Entity\Import;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Finding the encounter of a record (ADR 037). Candidates come only from the request's hospital and key
 * generation:
 *
 * - by allocation: the encounter the allocation is effectively assigned to (`encounter.allocation_id`, #1300);
 * - by case pseudonym: every encounter with the pseudonym.
 *
 * The allocation is the primary anchor. It decides when the pseudonym finds nothing or agrees with it;
 * a pseudonym pointing elsewhere is a contradiction. Without allocation, exactly one pseudonym match
 * decides; several are ambiguous. There is no time rule yet (open question in ADR 037), so the request's
 * time is only kept with pending records.
 *
 * @psalm-suppress UnusedClass Wired as EncounterAssignmentsInterface in config/services.yaml.
 */
final readonly class EncounterAssignments implements EncounterAssignmentsInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function find(AssignmentRequest $request): AssignmentResult
    {
        $byAllocation = null === $request->allocationId ? null : $this->linkedEncounter($request);
        $byCaseKey = null === $request->caseKey ? [] : $this->encountersWithCaseKey($request, $request->caseKey);

        if (null !== $byAllocation) {
            $allocationCandidate = new AssignmentCandidate($byAllocation, CandidateReason::Allocation);
            if ([] === $byCaseKey || \in_array($byAllocation, $byCaseKey, true)) {
                return AssignmentResult::assigned($allocationCandidate);
            }

            return AssignmentResult::contradictory([
                $allocationCandidate,
                ...array_map(static fn (int $id): AssignmentCandidate => new AssignmentCandidate($id, CandidateReason::CaseKey), $byCaseKey),
            ]);
        }

        return match (\count($byCaseKey)) {
            0 => AssignmentResult::noCandidate(),
            1 => AssignmentResult::assigned(new AssignmentCandidate($byCaseKey[0], CandidateReason::CaseKey)),
            default => AssignmentResult::ambiguous(array_map(static fn (int $id): AssignmentCandidate => new AssignmentCandidate($id, CandidateReason::CaseKey), $byCaseKey)),
        };
    }

    #[\Override]
    public function recordPending(SchemaWriteContext $context, string $schema, string $recordId, AssignmentRequest $request, AssignmentResult $result): void
    {
        if (AssignmentKind::Assigned === $result->kind) {
            return;
        }

        /** @var Hospital $hospital */
        $hospital = $this->entityManager->getReference(Hospital::class, $context->hospitalId);
        /** @var Import $import */
        $import = $this->entityManager->getReference(Import::class, $context->importId);

        $this->entityManager->persist(new EncounterPendingAssignment(
            hospital: $hospital,
            keyGeneration: $context->keyGeneration,
            import: $import,
            schema: $schema,
            recordId: $recordId,
            kind: $result->kind,
            allocationId: $request->allocationId,
            caseKey: $request->caseKey,
            requestedAt: $request->at,
            candidates: $result->candidates,
            createdAt: $this->clock->now(),
        ));
    }

    private function linkedEncounter(AssignmentRequest $request): ?int
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM encounter WHERE allocation_id = ? AND hospital_id = ? AND key_generation = ?',
            [$request->allocationId, $request->hospitalId, $request->keyGeneration],
        );

        return false === $id ? null : (int) $id;
    }

    /**
     * @return list<int>
     */
    private function encountersWithCaseKey(AssignmentRequest $request, string $caseKey): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM encounter WHERE hospital_id = ? AND key_generation = ? AND case_key = ? ORDER BY id',
            [$request->hospitalId, $request->keyGeneration, $caseKey],
        );

        return array_map(intval(...), $ids);
    }
}
