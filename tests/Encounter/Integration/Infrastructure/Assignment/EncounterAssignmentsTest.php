<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Assignment;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentRequest;
use App\Encounter\Contract\Assignment\AssignmentResult;
use App\Encounter\Contract\Assignment\CandidateReason;
use App\Encounter\Contract\Assignment\EncounterAssignmentsInterface;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EffectiveAssignments;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Finding the encounter of a record (ADR 037) with hand-made encounters of hospital H, key generation 1:
 *
 * - A (case 1), linked to allocation X;
 * - B and C (both case 2: one administrative case, two ED visits);
 * - D (case 3), linked to allocation Y only by a proposal, which is not effective;
 * - E of another hospital (case 4), linked to that hospital's allocation Z; F of H in generation 2 (case 5),
 *   linked to allocation W.
 */
#[ResetDatabase]
final class EncounterAssignmentsTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    /** @var array<string, int> encounter id by label */
    private array $encounters = [];

    /** @var array<string, int> allocation id by label */
    private array $allocations = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $other = $this->encounterHospital();
        $import = $this->encounterImport($this->hospital, 'ed visits');
        $otherImport = $this->encounterImport($other, 'other ed visits');
        $allocations = $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION);
        $otherAllocations = $this->encounterImport($other, 'other allocations', ImportType::ALLOCATION);

        $this->encounters = [
            'A' => $this->encounter($this->hospital, $import, 'A', '1'),
            'B' => $this->encounter($this->hospital, $import, 'B', '2'),
            'C' => $this->encounter($this->hospital, $import, 'C', '2'),
            'D' => $this->encounter($this->hospital, $import, 'D', '3'),
            'E' => $this->encounter($other, $otherImport, 'E', '4'),
            'F' => $this->encounter($this->hospital, $import, 'F', '5', keyGeneration: 2),
        ];
        foreach (['X' => ['A', MatchStatus::AutoAccepted], 'Y' => ['D', MatchStatus::Proposed], 'Z' => ['E', MatchStatus::AutoAccepted], 'W' => ['F', MatchStatus::AutoAccepted]] as $allocation => [$encounter, $status]) {
            $this->allocations[$allocation] = $this->link('E' === $encounter ? $otherAllocations : $allocations, $this->encounters[$encounter], $status);
        }
    }

    public function testUniqueCasePseudonymAssigns(): void
    {
        $result = $this->find(caseKey: '1');

        self::assertSame(AssignmentKind::Assigned, $result->kind);
        self::assertSame($this->encounters['A'], $result->encounterId);
        self::assertEquals([new AssignmentCandidate($this->encounters['A'], CandidateReason::CaseKey)], $result->candidates);
    }

    public function testUnknownCasePseudonymHasNoCandidate(): void
    {
        $result = $this->find(caseKey: 'unknown');

        self::assertSame(AssignmentKind::NoCandidate, $result->kind);
        self::assertNull($result->encounterId);
        self::assertSame([], $result->candidates);
    }

    public function testCasePseudonymOfSeveralEncountersIsAmbiguous(): void
    {
        $result = $this->find(caseKey: '2');

        self::assertSame(AssignmentKind::Ambiguous, $result->kind);
        self::assertNull($result->encounterId);
        self::assertEquals([
            new AssignmentCandidate($this->encounters['B'], CandidateReason::CaseKey),
            new AssignmentCandidate($this->encounters['C'], CandidateReason::CaseKey),
        ], $result->candidates);
    }

    public function testEffectiveLinkAssignsAloneAndWithAnAgreeingPseudonym(): void
    {
        $alone = $this->find(allocation: 'X');
        $agreeing = $this->find(allocation: 'X', caseKey: '1');

        foreach ([$alone, $agreeing] as $result) {
            self::assertSame(AssignmentKind::Assigned, $result->kind);
            self::assertSame($this->encounters['A'], $result->encounterId);
            self::assertEquals([new AssignmentCandidate($this->encounters['A'], CandidateReason::Allocation)], $result->candidates);
        }
    }

    public function testLinkAndPseudonymPointingElsewhereAreContradictoryAndKeepBothCandidates(): void
    {
        $result = $this->find(allocation: 'X', caseKey: '2');

        self::assertSame(AssignmentKind::Contradictory, $result->kind);
        self::assertNull($result->encounterId);
        self::assertEquals([
            new AssignmentCandidate($this->encounters['A'], CandidateReason::Allocation),
            new AssignmentCandidate($this->encounters['B'], CandidateReason::CaseKey),
            new AssignmentCandidate($this->encounters['C'], CandidateReason::CaseKey),
        ], $result->candidates);
    }

    public function testProposalIsNoLink(): void
    {
        self::assertSame(AssignmentKind::NoCandidate, $this->find(allocation: 'Y')->kind);
    }

    public function testLinkOutsideTheHospitalOrKeyGenerationIsNoCandidate(): void
    {
        // Z is another hospital's allocation linked to its visit E, W is linked to F of generation 2.
        self::assertSame(AssignmentKind::NoCandidate, $this->find(allocation: 'Z')->kind);
        self::assertSame(AssignmentKind::NoCandidate, $this->find(allocation: 'W')->kind);

        // Without a candidate from the link, the pseudonym alone decides.
        $result = $this->find(allocation: 'W', caseKey: '1');
        self::assertSame(AssignmentKind::Assigned, $result->kind);
        self::assertSame($this->encounters['A'], $result->encounterId);
        self::assertSame(CandidateReason::CaseKey, $result->candidates[0]->reason);

        // The pseudonym of F is found only within F's key generation.
        self::assertSame(AssignmentKind::NoCandidate, $this->find(caseKey: '5')->kind);
        self::assertSame($this->encounters['F'], $this->find(caseKey: '5', keyGeneration: 2)->encounterId);
    }

    public function testPendingRecordsKeepRequestAndCandidates(): void
    {
        $import = $this->encounterImport($this->hospital, 'stays');
        $context = new SchemaWriteContext((int) $this->hospital->getId(), (int) $import->getId(), 1);
        $at = new \DateTimeImmutable('2026-10-12 11:00');
        $ambiguous = new AssignmentRequest((int) $this->hospital->getId(), 1, caseKey: EncounterFileBuilder::caseKey('2'), at: $at);
        $assigned = new AssignmentRequest((int) $this->hospital->getId(), 1, caseKey: EncounterFileBuilder::caseKey('1'));

        $this->assignments()->recordPending($context, 'probe', 'r1', $ambiguous, $this->assignments()->find($ambiguous));
        $this->assignments()->recordPending($context, 'probe', 'r2', $assigned, $this->assignments()->find($assigned));
        $this->encounterEntityManager()->flush();

        $rows = $this->encounterEntityManager()->getConnection()->fetchAllAssociative('SELECT exchange_schema, record_id, kind, key_generation, allocation_id, case_key, requested_at, candidates FROM encounter_pending_assignment WHERE import_id = ?', [$import->getId()]);
        // jsonb keeps no key order, so the candidates are compared decoded.
        self::assertEquals([[
            'exchange_schema' => 'probe',
            'record_id' => 'r1',
            'kind' => 'ambiguous',
            'key_generation' => 1,
            'allocation_id' => null,
            'case_key' => EncounterFileBuilder::caseKey('2'),
            'requested_at' => '2026-10-12 11:00:00',
            'candidates' => [['encounter_id' => $this->encounters['B'], 'reason' => 'case_key'], ['encounter_id' => $this->encounters['C'], 'reason' => 'case_key']],
        ]], array_map(static fn (array $row): array => ['candidates' => json_decode((string) $row['candidates'], true)] + $row, $rows));
    }

    private function find(?string $allocation = null, ?string $caseKey = null, int $keyGeneration = 1): AssignmentResult
    {
        return $this->assignments()->find(new AssignmentRequest(
            (int) $this->hospital->getId(),
            $keyGeneration,
            null === $allocation ? null : $this->allocations[$allocation],
            null === $caseKey ? null : EncounterFileBuilder::caseKey($caseKey),
        ));
    }

    private function encounter(Hospital $hospital, Import $import, string $label, string $case, int $keyGeneration = 1): int
    {
        $id = $this->encounterRepository()->insertIfNewReturningId(new Encounter(
            hospital: $hospital,
            import: $import,
            keyGeneration: $keyGeneration,
            visitFingerprint: hash('sha256', 'visit|'.$label),
            caseKey: EncounterFileBuilder::caseKey($case),
            age: 54,
            sex: Sex::Female,
            times: new EncounterTimes(firstAssessmentContactAt: new \DateTimeImmutable('2026-10-12 08:15')),
        ));
        self::assertIsInt($id);

        return $id;
    }

    private function link(Import $allocationImport, int $encounterId, MatchStatus $status): int
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        self::assertInstanceOf(Encounter::class, $encounter);
        $allocation = $em->find(Allocation::class, $this->allocationAt($encounter->getHospital(), $allocationImport, '08:30')->getId());
        self::assertInstanceOf(Allocation::class, $allocation);

        $em->persist(new EncounterMatch($allocation, $encounter, $status, AnchorSource::Assessment, 7, new \DateTimeImmutable('2026-10-12 09:00'), 0.9697, 9, ['time' => 4], 1, '1.0.0'));
        $em->flush();
        EffectiveAssignments::syncFromMatches($em->getConnection());

        return (int) $allocation->getId();
    }

    private function assignments(): EncounterAssignmentsInterface
    {
        $service = self::getContainer()->get(EncounterAssignmentsInterface::class);
        self::assertInstanceOf(EncounterAssignmentsInterface::class, $service);

        return $service;
    }
}
