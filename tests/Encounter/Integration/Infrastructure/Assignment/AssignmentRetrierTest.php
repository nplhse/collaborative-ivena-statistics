<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Assignment;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Assignment\AssignmentDecisionOutcome;
use App\Encounter\Application\Assignment\AssignmentRetryResult;
use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Application\Contract\AssignmentConflictReaderInterface;
use App\Encounter\Application\Contract\AssignmentDeciderInterface;
use App\Encounter\Application\Contract\AssignmentRetrierInterface;
use App\Encounter\Application\Contract\EncounterKeyStateReaderInterface;
use App\Encounter\Application\Import\EncounterSchemaHandlerRegistry;
use App\Encounter\Contract\Assignment\AssignmentCandidate;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentRequest;
use App\Encounter\Contract\Assignment\AssignmentResult;
use App\Encounter\Contract\Assignment\CandidateReason;
use App\Encounter\Contract\Assignment\EncounterAssignmentsInterface;
use App\Encounter\Contract\Assignment\EncounterHospitalLockInterface;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Domain\Entity\EdVisit;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Infrastructure\Assignment\AssignmentRetrier;
use App\Encounter\Infrastructure\Assignment\PendingAssignmentRows;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\EffectiveAssignments;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Automatic retries (#1307) at service level with stays of the test bundle: row 3 has the case of visits B
 * and C (conflict), row 4 a case without visit (no candidate). Each test names its transition of the table in
 * AssignmentRetrier.
 */
#[ResetDatabase]
final class AssignmentRetrierTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    private Import $visitImport;

    private Import $stayImport;

    private User $reviewer;

    /** @var array<string, int> encounter id by label */
    private array $encounters = [];

    /** @var array<int|string, array{stay: int, pending: int}> stays by row number of the PoC fixture */
    private array $stays = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $this->reviewer = $this->user();
        $this->visitImport = $this->encounterImport($this->hospital, 'ed visits');
        $this->stayImport = $this->encounterImport($this->hospital, 'inpatient stays');
        $this->encounters['B'] = $this->encounter('B', 'BC');
        $this->encounters['C'] = $this->encounter('C', 'BC');

        $this->stays['3'] = $this->pendingStay('3', 'BC');
        $this->stays['4'] = $this->pendingStay('4', 'D');
    }

    public function testADeferredConflictWithTheSameCandidatesStaysDeferred(): void
    {
        $this->defer('3', 'C', 'B');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertEquals(new AssignmentRetryResult(unchanged: 2), $result, 'Row 3 (same B and C) and row 4 (still no candidate).');
        self::assertSame(['deferred', 'ambiguous'], $this->statusAndKind('3'));
        self::assertSame([], $this->reader()->conflictsOf($this->hospitalId(), 10));
    }

    public function testANewCandidateReopensADeferredConflict(): void
    {
        $this->defer('3', 'B', 'C');
        $this->encounters['F'] = $this->encounter('F', 'BC');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame(1, $result->reopened);
        self::assertSame(['open', 'ambiguous'], $this->statusAndKind('3'));
        self::assertSame($this->ids('B', 'C', 'F'), $this->candidateIds('3'));
        self::assertNull($this->pendingRow('3')['seen_kind'], 'An open record keeps no seen state.');
        self::assertSame(1, $this->decisionCount(), 'The earlier decision stays in the history.');
        $conflicts = $this->reader()->conflictsOf($this->hospitalId(), 10);
        self::assertCount(1, $conflicts);
        self::assertCount(3, $conflicts[0]->candidates);
    }

    public function testCandidatesShrinkingToOneAttachTheDeferredRecord(): void
    {
        $this->defer('3', 'B', 'C');
        $this->changeCase('C', 'other');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame(1, $result->attached);
        self::assertSame($this->encounters['B'], $this->stayEncounter('3'));
        self::assertFalse($this->pendingExists('3'));
    }

    public function testTheSameIdsWithAnotherKindReopenTheConflict(): void
    {
        // Row 5 names allocation X; nothing is linked to X yet, so the case decides: ambiguous B and C.
        $allocationImport = $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION);
        $allocationId = (int) $this->allocationAt($this->hospital, $allocationImport, '08:30')->getId();
        $this->stays['5'] = $this->pendingStay('5', 'BC', $allocationId);
        $this->defer('5', 'B', 'C');

        // X is linked to B, and B leaves the case: the link says B, the case says C.
        $this->linkToAllocation($allocationId, 'B');
        $this->changeCase('B', 'other');

        $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame(['open', 'contradictory'], $this->statusAndKind('5'));
        self::assertSame($this->ids('B', 'C'), $this->candidateIds('5'));
        self::assertNull($this->stayEncounter('5'), 'A contradictory result is never attached automatically.');
    }

    public function testAContradictionWithASingleCandidateIdIsNeverAttached(): void
    {
        $this->defer('3', 'B', 'C');
        // Only an `assigned` result attaches. A contradictory result stays a conflict, whatever its candidates.
        $b = $this->encounters['B'];
        $stub = new readonly class($b) implements EncounterAssignmentsInterface {
            public function __construct(private int $encounterId)
            {
            }

            #[\Override]
            public function find(AssignmentRequest $request): AssignmentResult
            {
                return AssignmentResult::contradictory([new AssignmentCandidate($this->encounterId, CandidateReason::Allocation)]);
            }

            #[\Override]
            public function recordPending(SchemaWriteContext $context, string $schema, string $recordId, AssignmentRequest $request, AssignmentResult $result): void
            {
            }
        };
        $container = self::getContainer();
        $lock = $container->get(EncounterHospitalLockInterface::class);
        $handlers = $container->get(EncounterSchemaHandlerRegistry::class);
        $keyStates = $container->get(EncounterKeyStateReaderInterface::class);
        self::assertInstanceOf(EncounterHospitalLockInterface::class, $lock);
        self::assertInstanceOf(EncounterSchemaHandlerRegistry::class, $handlers);
        self::assertInstanceOf(EncounterKeyStateReaderInterface::class, $keyStates);
        $retrier = new AssignmentRetrier($this->connection(), $lock, new PendingAssignmentRows($this->connection(), $stub, $handlers), $keyStates);

        $result = $retrier->retryHospital($this->hospitalId());

        self::assertSame(0, $result->attached);
        self::assertSame(['open', 'contradictory'], $this->statusAndKind('3'));
        self::assertSame($this->ids('B'), $this->candidateIds('3'));
        self::assertNull($this->stayEncounter('3'));
    }

    public function testDeferredCandidatesThatAllDisappearAndALateVisitD(): void
    {
        $this->defer('3', 'B', 'C');
        $this->changeCase('B', 'other');
        $this->changeCase('C', 'other');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame(1, $result->reopened);
        self::assertSame(['open', 'no_candidate'], $this->statusAndKind('3'));
        self::assertSame([], $this->candidateIds('3'));
        self::assertSame(1, $this->decisionCount(), 'The earlier decision stays in the history.');
        self::assertSame([], $this->reader()->conflictsOf($this->hospitalId(), 10));

        $this->encounters['D'] = $this->encounter('D', 'BC');
        $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame($this->encounters['D'], $this->stayEncounter('3'));
        self::assertFalse($this->pendingExists('3'));
    }

    public function testALateCandidateAttachesARecordWithoutCandidate(): void
    {
        $this->encounters['D'] = $this->encounter('D', 'D');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame(1, $result->attached);
        self::assertSame($this->encounters['D'], $this->stayEncounter('4'));
        self::assertFalse($this->pendingExists('4'));
    }

    public function testOpenConflictsWaitForReview(): void
    {
        $this->changeCase('C', 'other');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame(0, $result->attached + $result->reopened);
        self::assertSame(['open', 'ambiguous'], $this->statusAndKind('3'), 'The regular retry does not touch an open conflict.');
        self::assertNull($this->stayEncounter('3'));
    }

    public function testTheTargetedRetryAttachesAnOpenConflictWithAUniqueEncounter(): void
    {
        $seen = $this->state(AssignmentKind::Ambiguous, 'B', 'C');
        $this->changeCase('C', 'other');

        self::assertSame(AssignmentDecisionOutcome::UniqueCandidate, $this->decider()->assign($this->hospitalId(), $this->stays['3']['pending'], $seen, $this->encounters['B'], $this->reviewer));
        self::assertNull($this->stayEncounter('3'), 'The manual action decides nothing.');

        $result = $this->retrier()->retryOne($this->hospitalId(), $this->stays['3']['pending']);

        self::assertSame(1, $result->attached);
        self::assertSame($this->encounters['B'], $this->stayEncounter('3'));
        self::assertSame([], $this->reader()->conflictsOf($this->hospitalId(), 10));
        self::assertSame(0, $this->decisionCount(), 'An automatic assignment is no decision.');
    }

    public function testTheTargetedRetryStoresAFreshConflict(): void
    {
        $this->encounters['F'] = $this->encounter('F', 'BC');

        $result = $this->retrier()->retryOne($this->hospitalId(), $this->stays['3']['pending']);

        self::assertSame(1, $result->reopened);
        self::assertSame($this->ids('B', 'C', 'F'), $this->candidateIds('3'));
        self::assertEquals(new AssignmentRetryResult(unchanged: 1), $this->retrier()->retryOne($this->hospitalId(), $this->stays['3']['pending']));
    }

    public function testRecordsOfAnOlderKeyGenerationAreNotRetried(): void
    {
        $this->connection()->executeStatement('UPDATE encounter_pending_assignment SET key_generation = 0 WHERE id = ?', [$this->stays['4']['pending']]);
        $this->encounters['D'] = $this->encounter('D', 'D');

        self::assertEquals(new AssignmentRetryResult(), $this->retrier()->retryOne($this->hospitalId(), $this->stays['4']['pending']));
        $this->retrier()->retryHospital($this->hospitalId());

        self::assertNull($this->stayEncounter('4'));
        self::assertTrue($this->pendingExists('4'));
    }

    public function testARecordAttachedMeanwhileIsSkippedAndTheRunGoesOn(): void
    {
        $this->defer('3', 'B', 'C');
        $this->changeCase('C', 'other');
        $this->connection()->executeStatement('UPDATE test_inpatient_stay SET encounter_id = ? WHERE id = ?', [$this->encounters['C'], $this->stays['3']['stay']]);
        $this->encounters['D'] = $this->encounter('D', 'D');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertEquals(new AssignmentRetryResult(attached: 1, skipped: 1), $result);
        self::assertTrue($this->pendingExists('3'), 'The row stays for the next retry.');
        self::assertSame($this->encounters['D'], $this->stayEncounter('4'), 'The other record is still retried.');
    }

    public function testAnEncounterDeletedDuringTheRetryIsSkipped(): void
    {
        $this->encounters['D'] = $this->encounter('D', 'D');
        // A parallel ed_visit deletion (no hospital lock before #1300) removes D just before row 4 is attached.
        $this->connection()->executeStatement('CREATE FUNCTION test_delete_target() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN DELETE FROM encounter WHERE id = NEW.encounter_id; RETURN NEW; END $$');
        $this->connection()->executeStatement('CREATE TRIGGER test_delete_target BEFORE UPDATE ON test_inpatient_stay FOR EACH ROW EXECUTE FUNCTION test_delete_target()');

        $result = $this->retrier()->retryHospital($this->hospitalId());

        self::assertSame(1, $result->skipped);
        self::assertNull($this->stayEncounter('4'));
        self::assertTrue($this->pendingExists('4'));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE id = ?', [$this->encounters['D']]), 'The deletion was rolled back with the savepoint.');
    }

    public function testNothingIsRetriedDuringAKeyChange(): void
    {
        $this->encounters['D'] = $this->encounter('D', 'D');
        $this->connection()->executeStatement("INSERT INTO encounter_key_state (hospital_id, key_generation, retired_check_values, key_change_in_progress, created_at) VALUES (?, 1, '[]', true, NOW())", [$this->hospitalId()]);

        self::assertEquals(new AssignmentRetryResult(), $this->retrier()->retryHospital($this->hospitalId()));
        self::assertEquals(new AssignmentRetryResult(), $this->retrier()->retryOne($this->hospitalId(), $this->stays['4']['pending']));
        self::assertNull($this->stayEncounter('4'));
    }

    public function testAnAssignedResultIsNeverStoredAsPending(): void
    {
        $rows = self::getContainer()->get(PendingAssignmentRows::class);
        self::assertInstanceOf(PendingAssignmentRows::class, $rows);

        $this->expectException(\LogicException::class);
        $rows->reopen($this->stays['4']['pending'], AssignmentResult::assigned(new AssignmentCandidate($this->encounters['B'], CandidateReason::CaseKey)));
    }

    public function testTheCommandRefusesANegativeHospital(): void
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('app:encounter:retry-assignments'));

        self::assertSame(2, $tester->execute(['--hospital' => '-1']));
        self::assertStringContainsString('Pass --hospital=<id> or leave it out.', $tester->getDisplay());
    }

    public function testTheCommandRetriesEveryHospitalWithWaitingRecords(): void
    {
        $this->encounters['D'] = $this->encounter('D', 'D');

        self::assertSame([$this->hospitalId()], $this->retrier()->hospitalsWithRetryableRecords());
        $tester = new CommandTester(new Application(self::$kernel)->find('app:encounter:retry-assignments'));
        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('Retried the waiting encounter assignments.', $tester->getDisplay());
        self::assertSame($this->encounters['D'], $this->stayEncounter('4'));
    }

    private function encounter(string $label, string $case): int
    {
        $id = $this->encounterRepository()->insertIfNewReturningId(new EdVisit(
            hospital: $this->hospital,
            import: $this->visitImport,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'visit|'.$label),
            caseKey: EncounterFileBuilder::caseKey($case),
            age: 54,
            sex: Sex::Female,
            times: new EncounterTimes(firstAssessmentContactAt: new \DateTimeImmutable('2026-10-12 08:15')),
        ));
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @return array{stay: int, pending: int}
     */
    private function pendingStay(string $stay, string $case, ?int $allocationId = null): array
    {
        $stayId = (int) $this->connection()->fetchOne(
            'INSERT INTO test_inpatient_stay (hospital_id, key_generation, import_id, encounter_id, stay_fingerprint, case_key, admitted_at, discharge_diagnosis, discharged_at)
             VALUES (?, 1, ?, NULL, ?, ?, ?, ?, NULL) RETURNING id',
            [$this->hospitalId(), $this->stayImport->getId(), hash('sha256', 'stay|'.$stay), EncounterFileBuilder::caseKey($case), '2026-10-12 17:20:00', 'J18.9'],
        );
        $request = new AssignmentRequest($this->hospitalId(), 1, $allocationId, EncounterFileBuilder::caseKey($case), new \DateTimeImmutable('2026-10-12 17:20'));
        $assignments = self::getContainer()->get(EncounterAssignmentsInterface::class);
        self::assertInstanceOf(EncounterAssignmentsInterface::class, $assignments);
        $assignments->recordPending(new SchemaWriteContext($this->hospitalId(), (int) $this->stayImport->getId(), 1), InpatientStayDimension::SCHEMA, (string) $stayId, $request, $assignments->find($request));
        $this->encounterEntityManager()->flush();

        return ['stay' => $stayId, 'pending' => (int) $this->connection()->fetchOne('SELECT id FROM encounter_pending_assignment WHERE record_id = ?', [(string) $stayId])];
    }

    private function defer(string $stay, string $first, string $second, AssignmentKind $kind = AssignmentKind::Ambiguous): void
    {
        self::assertSame(AssignmentDecisionOutcome::Decided, $this->decider()->defer($this->hospitalId(), $this->stays[$stay]['pending'], $this->state($kind, $first, $second), $this->reviewer));
    }

    private function linkToAllocation(int $allocationId, string $label): void
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $this->encounters[$label]);
        $allocation = $em->find(Allocation::class, $allocationId);
        self::assertInstanceOf(Encounter::class, $encounter);
        self::assertInstanceOf(Allocation::class, $allocation);
        $em->persist(new EncounterMatch($allocation, $encounter, MatchStatus::AutoAccepted, AnchorSource::Assessment, 7, new \DateTimeImmutable('2026-10-12 09:00'), 0.9697, 9, ['time' => 4], 1, '1.0.0'));
        $em->flush();
        EffectiveAssignments::syncFromMatches($em->getConnection());
    }

    private function changeCase(string $label, string $case): void
    {
        $this->connection()->executeStatement('UPDATE encounter SET case_key = ? WHERE id = ?', [EncounterFileBuilder::caseKey($case), $this->encounters[$label]]);
    }

    private function state(AssignmentKind $kind, string ...$labels): AssignmentState
    {
        return new AssignmentState($kind, array_map(fn (string $label): int => $this->encounters[$label], $labels));
    }

    /**
     * @return list<int>
     */
    private function ids(string ...$labels): array
    {
        return $this->state(AssignmentKind::Ambiguous, ...$labels)->encounterIds;
    }

    /**
     * @return array{status: string, kind: string, candidates: string, seen_kind: ?string}
     */
    private function pendingRow(string $stay): array
    {
        /** @var array{status: string, kind: string, candidates: string, seen_kind: ?string}|false $row */
        $row = $this->connection()->fetchAssociative('SELECT status, kind, candidates, seen_kind FROM encounter_pending_assignment WHERE id = ?', [$this->stays[$stay]['pending']]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array{string, string}
     */
    private function statusAndKind(string $stay): array
    {
        $row = $this->pendingRow($stay);

        return [$row['status'], $row['kind']];
    }

    /**
     * @return list<int>
     */
    private function candidateIds(string $stay): array
    {
        /** @var list<array{encounter_id: int, reason: string}> $candidates */
        $candidates = json_decode($this->pendingRow($stay)['candidates'], true, flags: \JSON_THROW_ON_ERROR);

        return AssignmentState::stored(AssignmentKind::Ambiguous, $candidates)->encounterIds;
    }

    private function pendingExists(string $stay): bool
    {
        return 1 === (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_pending_assignment WHERE id = ?', [$this->stays[$stay]['pending']]);
    }

    private function stayEncounter(string $stay): ?int
    {
        $id = $this->connection()->fetchOne('SELECT encounter_id FROM test_inpatient_stay WHERE id = ?', [$this->stays[$stay]['stay']]);

        return null === $id ? null : (int) $id;
    }

    private function decisionCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_assignment_decision');
    }

    private function hospitalId(): int
    {
        return (int) $this->hospital->getId();
    }

    private function retrier(): AssignmentRetrierInterface
    {
        $retrier = self::getContainer()->get(AssignmentRetrierInterface::class);
        self::assertInstanceOf(AssignmentRetrierInterface::class, $retrier);

        return $retrier;
    }

    private function decider(): AssignmentDeciderInterface
    {
        $decider = self::getContainer()->get(AssignmentDeciderInterface::class);
        self::assertInstanceOf(AssignmentDeciderInterface::class, $decider);

        return $decider;
    }

    private function reader(): AssignmentConflictReaderInterface
    {
        $reader = self::getContainer()->get(AssignmentConflictReaderInterface::class);
        self::assertInstanceOf(AssignmentConflictReaderInterface::class, $reader);

        return $reader;
    }

    private function connection(): Connection
    {
        return $this->encounterEntityManager()->getConnection();
    }
}
