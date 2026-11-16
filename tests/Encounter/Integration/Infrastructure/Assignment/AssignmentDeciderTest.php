<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Assignment;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Assignment\AssignmentDecisionOutcome;
use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Application\Contract\AssignmentConflictReaderInterface;
use App\Encounter\Application\Contract\AssignmentDeciderInterface;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentRequest;
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
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The two manual decisions on an assignment conflict (#1307) at service level, with a stay of the test bundle
 * whose case matches visits B and C. Every decision re-checks the conflict and decides nothing when it
 * changed after the reviewer loaded the page.
 */
#[ResetDatabase]
final class AssignmentDeciderTest extends KernelTestCase
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

    private int $stayId;

    private int $pendingId;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $this->reviewer = $this->user();
        $this->visitImport = $this->encounterImport($this->hospital, 'ed visits');
        $this->stayImport = $this->encounterImport($this->hospital, 'inpatient stays');
        $this->encounters['A'] = $this->encounter('A', 'A');
        $this->encounters['B'] = $this->encounter('B', 'BC');
        $this->encounters['C'] = $this->encounter('C', 'BC');

        [$this->stayId, $this->pendingId] = $this->pendingStay('3', 'BC');
    }

    public function testAssignAttachesTheRecordAndKeepsTheDecision(): void
    {
        $outcome = $this->decider()->assign($this->hospitalId(), $this->pendingId, $this->seen(AssignmentKind::Ambiguous, 'B', 'C'), $this->encounters['B'], $this->reviewer);

        self::assertSame(AssignmentDecisionOutcome::Decided, $outcome);
        self::assertSame($this->encounters['B'], $this->stayEncounter());
        self::assertSame(0, $this->pendingCount());
        self::assertSame([[
            'action' => 'assigned',
            'seen_kind' => 'ambiguous',
            'encounter_id' => $this->encounters['B'],
            'decided_by_id' => $this->reviewer->getId(),
            'record_id' => (string) $this->stayId,
            'import_id' => $this->stayImport->getId(),
        ]], $this->decisions());
    }

    public function testDeferClosesTheConflictAndKeepsWhatTheReviewerSaw(): void
    {
        $outcome = $this->decider()->defer($this->hospitalId(), $this->pendingId, $this->seen(AssignmentKind::Ambiguous, 'C', 'B'), $this->reviewer);

        self::assertSame(AssignmentDecisionOutcome::Decided, $outcome);
        self::assertNull($this->stayEncounter());
        $row = $this->pendingRow();
        self::assertSame(['deferred', 'ambiguous', $this->reviewer->getId()], [$row['status'], $row['seen_kind'], $row['decided_by_id']]);
        self::assertSame($this->ids('B', 'C'), $this->candidateIds($row['seen_candidates']));
        self::assertNotNull($row['decided_at']);
        self::assertSame(['deferred'], array_column($this->decisions(), 'action'));

        $reader = $this->reader();
        self::assertSame([], $reader->conflictsOf($this->hospitalId(), 10));
        self::assertSame(0, $reader->countOf($this->hospitalId()));
        $deferred = $reader->deferredOf($this->hospitalId(), 10);
        self::assertCount(1, $deferred);
        self::assertSame([(string) $this->stayId, $this->reviewer->getUsername(), AssignmentKind::Ambiguous], [$deferred[0]->recordId, $deferred[0]->decidedBy, $deferred[0]->kind]);
        self::assertSame(1, $reader->countDeferredOf($this->hospitalId()));
    }

    public function testANewCandidateAfterTheFormWasLoadedPreventsBothDecisions(): void
    {
        $seen = $this->seen(AssignmentKind::Ambiguous, 'B', 'C');
        $this->encounters['F'] = $this->encounter('F', 'BC');

        self::assertSame(AssignmentDecisionOutcome::Changed, $this->decider()->assign($this->hospitalId(), $this->pendingId, $seen, $this->encounters['B'], $this->reviewer));
        self::assertSame(AssignmentDecisionOutcome::Changed, $this->decider()->defer($this->hospitalId(), $this->pendingId, $seen, $this->reviewer));

        self::assertNull($this->stayEncounter());
        $row = $this->pendingRow();
        self::assertSame(['open', 'ambiguous'], [$row['status'], $row['kind']]);
        self::assertSame($this->ids('B', 'C', 'F'), $this->candidateIds($row['candidates']), 'The record shows the fresh conflict.');
        self::assertSame([], $this->decisions());

        $fresh = $this->seen(AssignmentKind::Ambiguous, 'B', 'C', 'F');
        self::assertSame(AssignmentDecisionOutcome::Decided, $this->decider()->assign($this->hospitalId(), $this->pendingId, $fresh, $this->encounters['F'], $this->reviewer), 'The fresh state can be decided.');
    }

    public function testACandidateThatDisappearsLeavesTheConflictUndecided(): void
    {
        $seen = $this->seen(AssignmentKind::Ambiguous, 'B', 'C');
        $this->changeCase('C', 'other');

        self::assertSame(AssignmentDecisionOutcome::UniqueCandidate, $this->decider()->assign($this->hospitalId(), $this->pendingId, $seen, $this->encounters['B'], $this->reviewer));
        self::assertSame(AssignmentDecisionOutcome::UniqueCandidate, $this->decider()->defer($this->hospitalId(), $this->pendingId, $seen, $this->reviewer));

        self::assertNull($this->stayEncounter(), 'A unique candidate is not attached by a manual action; the targeted retry does that.');
        $row = $this->pendingRow();
        self::assertSame(['open', 'ambiguous'], [$row['status'], $row['kind']]);
        self::assertSame($this->ids('B', 'C'), $this->candidateIds($row['candidates']));
        self::assertSame([], $this->decisions());
    }

    public function testAllCandidatesGoneTurnsTheRecordIntoNoCandidate(): void
    {
        $seen = $this->seen(AssignmentKind::Ambiguous, 'B', 'C');
        $this->changeCase('B', 'other');
        $this->changeCase('C', 'other');

        self::assertSame(AssignmentDecisionOutcome::Changed, $this->decider()->defer($this->hospitalId(), $this->pendingId, $seen, $this->reviewer));

        $row = $this->pendingRow();
        self::assertSame(['open', 'no_candidate', []], [$row['status'], $row['kind'], $this->candidateIds($row['candidates'])]);
        self::assertSame([], $this->reader()->conflictsOf($this->hospitalId(), 10), 'A record without candidate is no conflict.');
    }

    public function testAKindChangeWithTheSameCandidatesIsAChange(): void
    {
        // The stay names allocation X; nothing is linked to X yet, so the case decides: ambiguous B and C.
        $allocationImport = $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION);
        $allocationId = (int) $this->allocationAt($this->hospital, $allocationImport, '08:30')->getId();
        [, $pendingId] = $this->pendingStay('5', 'BC', $allocationId);
        $seen = $this->seen(AssignmentKind::Ambiguous, 'B', 'C');

        // X is linked to B, and B leaves the case: the link says B, the case says C.
        $this->linkToAllocation($allocationId, 'B');
        $this->changeCase('B', 'other');

        self::assertSame(AssignmentDecisionOutcome::Changed, $this->decider()->assign($this->hospitalId(), $pendingId, $seen, $this->encounters['C'], $this->reviewer));
        $row = $this->pendingRow($pendingId);
        self::assertSame(['open', 'contradictory'], [$row['status'], $row['kind']]);
        self::assertSame($this->ids('B', 'C'), $this->candidateIds($row['candidates']));
    }

    public function testOnlyOpenConflictsCanBeDecided(): void
    {
        [, $withoutCandidate] = $this->pendingStay('4', 'none');
        $seen = $this->seen(AssignmentKind::Ambiguous, 'B', 'C');

        self::assertSame(AssignmentDecisionOutcome::NotOpen, $this->decider()->defer($this->hospitalId(), $withoutCandidate, $seen, $this->reviewer), 'A record without candidate is open but no conflict.');
        self::assertSame(AssignmentDecisionOutcome::Decided, $this->decider()->defer($this->hospitalId(), $this->pendingId, $seen, $this->reviewer));
        self::assertSame(AssignmentDecisionOutcome::NotOpen, $this->decider()->assign($this->hospitalId(), $this->pendingId, $seen, $this->encounters['B'], $this->reviewer), 'A deferred record is closed.');
        self::assertSame(AssignmentDecisionOutcome::NotOpen, $this->decider()->defer($this->hospitalId(), 999_999, $seen, $this->reviewer));

        $other = $this->encounterHospital();
        self::assertSame(AssignmentDecisionOutcome::NotOpen, $this->decider()->assign((int) $other->getId(), $this->pendingId, $seen, $this->encounters['B'], $this->reviewer), 'A conflict belongs to its hospital.');
        self::assertNull($this->stayEncounter());
    }

    public function testTheChosenEncounterMustBeACandidate(): void
    {
        $outcome = $this->decider()->assign($this->hospitalId(), $this->pendingId, $this->seen(AssignmentKind::Ambiguous, 'B', 'C'), $this->encounters['A'], $this->reviewer);

        self::assertSame(AssignmentDecisionOutcome::InvalidCandidate, $outcome);
        self::assertNull($this->stayEncounter());
        self::assertSame(1, $this->pendingCount());
        self::assertSame([], $this->decisions());
    }

    public function testARecordAttachedMeanwhileIsNotChangedAndStaysPending(): void
    {
        $this->connection()->executeStatement('UPDATE test_inpatient_stay SET encounter_id = ? WHERE id = ?', [$this->encounters['C'], $this->stayId]);

        $outcome = $this->decider()->assign($this->hospitalId(), $this->pendingId, $this->seen(AssignmentKind::Ambiguous, 'B', 'C'), $this->encounters['B'], $this->reviewer);

        self::assertSame(AssignmentDecisionOutcome::RecordChanged, $outcome);
        self::assertSame($this->encounters['C'], $this->stayEncounter(), 'attach() updates only a record without encounter.');
        self::assertSame(1, $this->pendingCount(), 'The pending record goes only after a successful attach.');
        self::assertSame([], $this->decisions());
    }

    public function testAnEncounterDeletedDuringTheDecisionRollsEverythingBack(): void
    {
        // A parallel ed_visit deletion (which does not take the hospital lock before #1300) removes B just
        // before the record is attached to it.
        $this->connection()->executeStatement('CREATE FUNCTION test_delete_target() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN DELETE FROM encounter WHERE id = NEW.encounter_id; RETURN NEW; END $$');
        $this->connection()->executeStatement('CREATE TRIGGER test_delete_target BEFORE UPDATE ON test_inpatient_stay FOR EACH ROW EXECUTE FUNCTION test_delete_target()');

        $outcome = $this->decider()->assign($this->hospitalId(), $this->pendingId, $this->seen(AssignmentKind::Ambiguous, 'B', 'C'), $this->encounters['B'], $this->reviewer);

        self::assertSame(AssignmentDecisionOutcome::EncounterGone, $outcome);
        self::assertNull($this->stayEncounter());
        self::assertSame(1, $this->pendingCount());
        self::assertSame([], $this->decisions());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE id = ?', [$this->encounters['B']]), 'The deletion was rolled back with the decision.');
    }

    private function encounter(string $label, string $case): int
    {
        $id = $this->encounterRepository()->insertIfNewReturningId(new Encounter(
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
     * A stay of the test bundle without encounter and its pending record, as the bundle's handler writes them.
     *
     * @return array{int, int} stay id and pending id
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

        $pendingId = (int) $this->connection()->fetchOne('SELECT id FROM encounter_pending_assignment WHERE record_id = ?', [(string) $stayId]);
        self::assertGreaterThan(0, $pendingId);

        return [$stayId, $pendingId];
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
    }

    private function changeCase(string $label, string $case): void
    {
        $this->connection()->executeStatement('UPDATE encounter SET case_key = ? WHERE id = ?', [EncounterFileBuilder::caseKey($case), $this->encounters[$label]]);
    }

    private function seen(AssignmentKind $kind, string ...$labels): AssignmentState
    {
        return new AssignmentState($kind, array_map(fn (string $label): int => $this->encounters[$label], $labels));
    }

    /**
     * @return list<int>
     */
    private function ids(string ...$labels): array
    {
        return $this->seen(AssignmentKind::Ambiguous, ...$labels)->encounterIds;
    }

    /**
     * @return list<int>
     */
    private function candidateIds(?string $json): array
    {
        /** @var list<array{encounter_id: int, reason: string}> $candidates */
        $candidates = json_decode($json ?? '[]', true, flags: \JSON_THROW_ON_ERROR);

        return AssignmentState::stored(AssignmentKind::Ambiguous, $candidates)->encounterIds;
    }

    private function stayEncounter(): ?int
    {
        $id = $this->connection()->fetchOne('SELECT encounter_id FROM test_inpatient_stay WHERE id = ?', [$this->stayId]);

        return null === $id ? null : (int) $id;
    }

    /**
     * @return array{status: string, kind: string, candidates: string, seen_kind: ?string, seen_candidates: ?string, decided_by_id: ?int, decided_at: ?string}
     */
    private function pendingRow(?int $pendingId = null): array
    {
        /** @var array{status: string, kind: string, candidates: string, seen_kind: ?string, seen_candidates: ?string, decided_by_id: int|string|null, decided_at: ?string}|false $row */
        $row = $this->connection()->fetchAssociative('SELECT status, kind, candidates, seen_kind, seen_candidates, decided_by_id, decided_at FROM encounter_pending_assignment WHERE id = ?', [$pendingId ?? $this->pendingId]);
        self::assertIsArray($row);
        $row['decided_by_id'] = null === $row['decided_by_id'] ? null : (int) $row['decided_by_id'];

        return $row;
    }

    private function pendingCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_pending_assignment WHERE id = ?', [$this->pendingId]);
    }

    /**
     * @return list<array{action: string, seen_kind: string, encounter_id: ?int, decided_by_id: ?int, record_id: string, import_id: ?int}>
     */
    private function decisions(): array
    {
        /** @var list<array{action: string, seen_kind: string, encounter_id: int|string|null, decided_by_id: int|string|null, record_id: string, import_id: int|string}> $rows */
        $rows = $this->connection()->fetchAllAssociative('SELECT action, seen_kind, encounter_id, decided_by_id, record_id, import_id FROM encounter_assignment_decision ORDER BY id');

        return array_map(static fn (array $row): array => [
            'action' => $row['action'],
            'seen_kind' => $row['seen_kind'],
            'encounter_id' => null === $row['encounter_id'] ? null : (int) $row['encounter_id'],
            'decided_by_id' => null === $row['decided_by_id'] ? null : (int) $row['decided_by_id'],
            'record_id' => $row['record_id'],
            'import_id' => (int) $row['import_id'],
        ], $rows);
    }

    private function hospitalId(): int
    {
        return (int) $this->hospital->getId();
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
