<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Encounter\Poc\InpatientStayPoc;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Reprocessing an ED import (#1300, ADR 029): visits reconcile by fingerprint inside one write transaction.
 * Visit "a" at 08:15 is linked to allocation X (08:30); "b" and "c" are unlinked.
 */
#[ResetDatabase]
final class EncounterReprocessTest extends KernelTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    private User $uploader;

    private int $allocationX;

    private int $edImportId;

    /** @var array<string, int> */
    private array $visits;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
        $this->allocationX = (int) $this->allocationAt($this->hospital, $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION), '08:30')->getId();
        $this->edImportId = $this->uploadEncounterFile($this->hospital, $this->uploader, $this->file('a', 'b', 'c')->build(), 'ed visits');
        $this->visits = $this->visitIdsOfImport($this->edImportId, 'a', 'b', 'c');
        $this->linkVisit($this->allocationX, $this->visits['a']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testKeptVisitsStayRemovedOnesGoNewOnesArrive(): void
    {
        $uids = $this->uids();
        $this->replaceSourceFile($this->edImportId, $this->file('a', 'c', 'd')->build());

        $this->runEncounterImport($this->edImportId);

        $after = $this->visitIdsOfImport($this->edImportId, 'a', 'b', 'c', 'd');
        self::assertSame(['a', 'c', 'd'], array_keys($after));
        self::assertSame($this->visits['a'], $after['a'], 'A kept visit keeps its encounter.');
        self::assertSame($this->visits['c'], $after['c']);
        self::assertSame([$uids['a'], $uids['c']], [$this->uids()['a'], $this->uids()['c']], 'and its uid (the visit URL).');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE id = ?', [$this->visits['b']]), 'The removed visit\'s encounter stays until the cleanup.');
        self::assertSame($this->visits['a'], $this->encounterOfX(), 'The effective assignment is unchanged.');
        self::assertSame(3, $this->encounterImportOf($this->edImportId)->getInserted());
        self::assertSame(ImportStatus::COMPLETED, $this->freshImport($this->edImportId)->getStatus());
    }

    public function testAKeptVisitWithAnAttachedStayKeepsEncounterStayAssignmentAndUrl(): void
    {
        $uids = $this->uids();
        $stayImportId = $this->uploadEncounterFile($this->hospital, $this->uploader, InpatientStayPoc::stays(), 'stays', schema: InpatientStayDimension::SCHEMA);
        $stayOfA = (int) $this->connection()->fetchOne('SELECT id FROM test_inpatient_stay WHERE import_id = ? AND encounter_id = ?', [$stayImportId, $this->visits['a']]);
        self::assertGreaterThan(0, $stayOfA);

        $this->runEncounterImport($this->edImportId);

        self::assertSame($this->visits['a'], (int) $this->connection()->fetchOne('SELECT encounter_id FROM test_inpatient_stay WHERE id = ?', [$stayOfA]));
        self::assertSame($this->visits['a'], $this->encounterOfX());
        self::assertSame($uids, $this->uids());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_match WHERE encounter_id = ? AND allocation_id = ?', [$this->visits['a'], $this->allocationX]), 'The ED matching history of a kept visit stays.');
    }

    public function testAFailedReprocessKeepsTheLastSuccessfulRun(): void
    {
        $before = $this->snapshot($this->edImportId);
        $this->replaceSourceFile($this->edImportId, $this->file('a', 'c', 'd')->visit('e', '2026-10-12T20:00', overrides: ['spo2' => '120'])->build());
        // Fails late: after the reconciliation removed b and refreshed a and c, while d is inserted.
        $this->connection()->executeStatement(sprintf(
            "CREATE FUNCTION test_fail_on_d() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.visit_fingerprint = '%s' THEN RAISE EXCEPTION 'test failure'; END IF; RETURN NEW; END \$\$",
            EdVisitFileBuilder::fingerprint('d'),
        ));
        $this->connection()->executeStatement('CREATE TRIGGER test_fail_on_d BEFORE INSERT ON ed_visit FOR EACH ROW EXECUTE FUNCTION test_fail_on_d()');

        $this->runEncounterImport($this->edImportId);

        self::assertSame(ImportStatus::FAILED, $this->freshImport($this->edImportId)->getStatus(), 'The new run is shown as failed.');
        self::assertSame($before, $this->snapshot($this->edImportId), 'Visits, uids, assignment, matching history, rejects and counters are those of the last successful run.');
    }

    public function testAFailedReprocessOfAStayImportKeepsItsPendingRecordsAndDecisions(): void
    {
        // Visits B and C share the case of stay row 3, which becomes an ambiguous conflict.
        $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('B2', '2026-10-12T10:15', 'BC')
            ->visit('C2', '2026-10-12T16:40', 'BC')
            ->build(), 'more visits');
        $stayImportId = $this->uploadEncounterFile($this->hospital, $this->uploader, InpatientStayPoc::stays(), 'stays', schema: InpatientStayDimension::SCHEMA);
        $conflict = (int) $this->connection()->fetchOne("SELECT id FROM encounter_pending_assignment WHERE import_id = ? AND kind = 'ambiguous'", [$stayImportId]);
        $this->connection()->executeStatement(
            "UPDATE encounter_pending_assignment SET status = 'deferred', seen_kind = kind, seen_candidates = candidates, decided_at = '2026-11-16 10:00:00', decided_by_id = ? WHERE id = ?",
            [$this->uploader->getId(), $conflict],
        );
        $this->connection()->executeStatement(
            "INSERT INTO encounter_assignment_decision (hospital_id, import_id, exchange_schema, record_id, action, seen_kind, seen_candidates, encounter_id, decided_by_id, decided_at)
             SELECT hospital_id, import_id, exchange_schema, record_id, 'deferred', kind, candidates, NULL, ?, '2026-11-16 10:00:00' FROM encounter_pending_assignment WHERE id = ?",
            [$this->uploader->getId(), $conflict],
        );
        $pending = $this->connection()->fetchAllAssociative('SELECT exchange_schema, record_id, status, kind, candidates FROM encounter_pending_assignment WHERE import_id = ? ORDER BY record_id', [$stayImportId]);
        $stays = $this->connection()->fetchAllAssociative('SELECT id, encounter_id FROM test_inpatient_stay WHERE import_id = ? ORDER BY id', [$stayImportId]);
        $this->connection()->executeStatement("CREATE FUNCTION test_fail_on_stay() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'test failure'; END \$\$");
        $this->connection()->executeStatement('CREATE TRIGGER test_fail_on_stay BEFORE INSERT ON test_inpatient_stay FOR EACH ROW EXECUTE FUNCTION test_fail_on_stay()');

        $this->runEncounterImport($stayImportId);

        self::assertSame(ImportStatus::FAILED, $this->freshImport($stayImportId)->getStatus());
        self::assertSame($pending, $this->connection()->fetchAllAssociative('SELECT exchange_schema, record_id, status, kind, candidates FROM encounter_pending_assignment WHERE import_id = ? ORDER BY record_id', [$stayImportId]));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_assignment_decision WHERE import_id = ?', [$stayImportId]));
        self::assertSame($stays, $this->connection()->fetchAllAssociative('SELECT id, encounter_id FROM test_inpatient_stay WHERE import_id = ? ORDER BY id', [$stayImportId]));
    }

    public function testAChangedFingerprintNeverTakesAnAllocationThatIsAssignedToAnotherEncounter(): void
    {
        // a's ED admission is documented later now: a new fingerprint, so a new visit with a new encounter. The old
        // encounter keeps allocation X; the matcher does not move X to the new visit.
        $this->replaceSourceFile($this->edImportId, EdVisitFileBuilder::create()
            ->visit('a changed', '2026-10-12T08:16', 'A')
            ->visit('b', '2026-10-12T09:15', 'B')
            ->visit('c', '2026-10-12T15:40', 'C')
            ->build());

        $this->runEncounterImport($this->edImportId);

        $changed = $this->visitIdsOfImport($this->edImportId, 'a changed')['a changed'] ?? null;
        self::assertNotNull($changed);
        self::assertNotSame($this->visits['a'], $changed);
        self::assertSame($this->visits['a'], $this->encounterOfX(), 'X stays with the encounter it was assigned to.');
        self::assertNull($this->connection()->fetchOne('SELECT allocation_id FROM encounter WHERE id = ?', [$changed]));
    }

    private function file(string ...$labels): EdVisitFileBuilder
    {
        $times = ['a' => '2026-10-12T08:15', 'b' => '2026-10-12T09:15', 'c' => '2026-10-12T15:40', 'd' => '2026-10-12T18:05'];
        $file = EdVisitFileBuilder::create();
        foreach ($labels as $label) {
            // Upper-case case labels, as in InpatientStayPoc: the stay of row 1 has the case of visit A.
            $file = $file->visit($label, $times[$label], strtoupper($label));
        }

        return $file;
    }

    private function replaceSourceFile(int $importId, string $content): void
    {
        new Filesystem()->dumpFile($this->projectDir().'/'.$this->freshImport($importId)->getFilePath(), $content);
    }

    private function freshImport(int $importId): Import
    {
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }

    private function encounterOfX(): ?int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM encounter WHERE allocation_id = ?', [$this->allocationX]);

        return false === $id ? null : (int) $id;
    }

    /**
     * @return array<string, string> visit uid by label
     */
    private function uids(): array
    {
        $uids = [];
        foreach (['a', 'b', 'c'] as $label) {
            $uid = $this->connection()->fetchOne('SELECT visit_uid FROM ed_visit WHERE visit_fingerprint = ?', [EdVisitFileBuilder::fingerprint($label)]);
            if (\is_string($uid)) {
                $uids[$label] = $uid;
            }
        }

        return $uids;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $importId): array
    {
        $connection = $this->connection();
        $encounterImport = $this->encounterImportOf($importId);

        return [
            'visits' => $connection->fetchAllAssociative('SELECT encounter_id, visit_uid, visit_fingerprint, mts_category, ed_admission_at FROM ed_visit WHERE import_id = ? ORDER BY encounter_id', [$importId]),
            'assignments' => $connection->fetchAllAssociative('SELECT id, allocation_id FROM encounter WHERE allocation_id IS NOT NULL ORDER BY id'),
            'matches' => $connection->fetchAllAssociative('SELECT allocation_id, encounter_id, status FROM encounter_match ORDER BY id'),
            'rejects' => (int) $connection->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]),
            // The report of the encounter import; the import row describes the failed run itself (status, row counts).
            'counters' => [$encounterImport->getInserted(), $encounterImport->getDuplicates(), $encounterImport->getRejected(), $encounterImport->getSuspectedDuplicates()],
        ];
    }
}
