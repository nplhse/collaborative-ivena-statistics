<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Event\EncountersImported;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportStatus;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Encounter import runs with small synthetic files (#1048, ADR 026, ADR 027, ADR 029).
 *
 * Visits are named by labels; the same label is the same visit (same fingerprint). Anchors are on
 * 1 October 2026 unless stated otherwise.
 */
#[ResetDatabase]
final class EncounterImportRunTest extends KernelTestCase
{
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private Hospital $hospital;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testNewFileInsertsEveryVisitAndRegistersTheKeyCheckValue(): void
    {
        $events = $this->recordEvents();
        $importId = $this->upload(EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->visit('b', '2026-10-01T23:50')
            ->visit('c', '2026-10-03T00:05'));

        $import = $this->import($importId);
        self::assertSame(ImportStatus::COMPLETED, $import->getStatus());
        self::assertSame(3, $import->getRowCount());
        self::assertSame(3, $import->getRowsPassed());
        self::assertSame(0, $import->getRowsRejected());
        self::assertSame(['2026-10-01' => 2, '2026-10-03' => 1], $import->getDataCoverage()?->dailyCounts);
        $this->assertCounts($importId, inserted: 3, duplicates: 0, skipped: 0, rejected: 0, suspected: 0);
        self::assertSame('orbis-mts', $this->encounterImportOf($importId)->getProfile());
        self::assertSame(EdVisitFileBuilder::KEY_CHECK, $this->connection()->fetchOne('SELECT active_check_value FROM encounter_key_state WHERE hospital_id = ?', [$this->hospital->getId()]));
        self::assertSame(3, $this->encounterCount($importId));

        self::assertCount(1, $events);
        self::assertSame((int) $this->hospital->getId(), $events[0]->hospitalId);
        self::assertSame($importId, $events[0]->importId);
        self::assertSame(['2026-10-01', '2026-10-03'], $events[0]->days);
    }

    public function testFileMixingKnownAndNewVisitsImportsOnlyTheNewOnes(): void
    {
        $first = $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->visit('b', '2026-10-01T09:00'), 'first');
        $events = $this->recordEvents();

        // b is known from the first file, c and d are new; the row of b differs (age), which is not applied.
        $second = $this->upload(EdVisitFileBuilder::create()
            ->visit('b', '2026-10-01T09:00', overrides: ['age' => '60'])
            ->visit('c', '2026-10-02T10:00')
            ->visit('d', '2026-10-02T11:00'), 'second');

        $this->assertCounts($second, inserted: 2, duplicates: 1, skipped: 0, rejected: 0, suspected: 0);
        self::assertSame(1, $this->import($second)->getRowsDeduplicated());
        self::assertSame(['c', 'd'], array_keys($this->visitIdsOfImport($second, 'b', 'c', 'd')));
        self::assertSame(['a', 'b'], array_keys($this->visitIdsOfImport($first, 'a', 'b')));
        self::assertSame(54, (int) $this->connection()->fetchOne('SELECT age FROM encounter WHERE visit_fingerprint = ?', [EdVisitFileBuilder::fingerprint('b')]));
        // The coverage describes the file: the known visit counts on its day.
        self::assertSame(['2026-10-01' => 1, '2026-10-02' => 2], $this->import($second)->getDataCoverage()?->dailyCounts);
        self::assertSame(['2026-10-02'], $events[0]->days ?? null);
    }

    public function testChangedAnchorWithinFifteenMinutesIsReportedAsSuspectedDuplicate(): void
    {
        $first = $this->upload(EdVisitFileBuilder::create()
            ->visit('case-1 at 08:15', '2026-10-01T08:15', case: 'case-1')
            ->visit('case-2 at 10:00', '2026-10-01T10:00', case: 'case-2'), 'first');

        // case-1: assessment entered later, so the anchor (and fingerprint) changed by 12 minutes.
        // case-2: a second visit of the same case 16 minutes later is no suspected duplicate.
        $second = $this->upload(EdVisitFileBuilder::create()
            ->visit('case-1 at 08:27', '2026-10-01T08:27', case: 'case-1')
            ->visit('case-2 at 10:16', '2026-10-01T10:16', case: 'case-2'), 'second');

        $this->assertCounts($second, inserted: 2, duplicates: 0, skipped: 0, rejected: 0, suspected: 1);
        $reports = $this->connection()->fetchAllAssociative(
            'SELECT sd.line_number, sd.anchor_difference_minutes, sd.encounter_id, sd.existing_encounter_id FROM encounter_suspected_duplicate sd WHERE sd.import_id = ?',
            [$second],
        );
        self::assertSame([[
            'line_number' => 3,
            'anchor_difference_minutes' => 12,
            'encounter_id' => $this->visitIdsOfImport($second, 'case-1 at 08:27')['case-1 at 08:27'],
            'existing_encounter_id' => $this->visitIdsOfImport($first, 'case-1 at 08:15')['case-1 at 08:15'],
        ]], $reports);
        // Never merged: both visits exist.
        self::assertSame(2, $this->encounterCount($first));
        self::assertSame(2, $this->encounterCount($second));
    }

    public function testRowRejectsRecordLineFieldCodeAndProfileVersionButNoCellValue(): void
    {
        $importId = $this->upload(EdVisitFileBuilder::create()
            ->visit('ok-1', '2026-10-01T08:00')
            ->visit('vital out of range', '2026-10-01T08:10', overrides: ['heart_rate' => '377'])
            ->visit('no anchor', '', overrides: ['first_assessment_contact_at' => ''])
            ->visit('bad code', '2026-10-01T08:30', overrides: ['sex' => 'Q', 'glucose' => '12.5'])
            ->visit('ok-2', '2026-10-01T08:40')
            ->visit('ok-3', '2026-10-01T08:50')
            ->visit('ok-4', '2026-10-01T09:00')
            ->visit('ok-5', '2026-10-01T09:10'));

        $this->assertCounts($importId, inserted: 5, duplicates: 0, skipped: 1, rejected: 2, suspected: 0);
        $import = $this->import($importId);
        self::assertSame(8, $import->getRowCount());
        self::assertSame(2, $import->getRowsRejected());
        self::assertSame(ImportStatus::PARTIAL, $import->getStatus(), '2 of 8 rows rejected (25 %) is a partial import.');

        $rejects = $this->connection()->fetchAllAssociative('SELECT line_number, messages, "row" AS context FROM import_reject WHERE import_id = ? ORDER BY line_number', [$importId]);
        self::assertSame([
            ['line_number' => 4, 'messages' => ['heart_rate: out_of_range'], 'context' => ['profile' => 'orbis-mts', 'profile_version' => '1']],
            ['line_number' => 6, 'messages' => ['sex: invalid_code', 'glucose: invalid_format'], 'context' => ['profile' => 'orbis-mts', 'profile_version' => '1']],
        ], array_map(static fn (array $row): array => [
            'line_number' => $row['line_number'],
            'messages' => json_decode((string) $row['messages'], true),
            'context' => json_decode((string) $row['context'], true),
        ], $rejects));

        $stored = json_encode($rejects, \JSON_THROW_ON_ERROR);
        foreach (['377', '12.5', '"Q"', EdVisitFileBuilder::fingerprint('bad code'), EdVisitFileBuilder::caseKey('bad code')] as $cellValue) {
            self::assertStringNotContainsString($cellValue, $stored);
        }
    }

    public function testRawVendorExportIsRejectedAndDeletedEvenWithTheConfirmationTicked(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::rawVendorExport(), 'orbis raw');

        $this->assertFileRejected($importId, EncounterFileRejection::RawExport, deleted: true);
        self::assertNotNull($this->encounterImportOf($importId)->getConfirmedBy(), 'The upload was confirmed.');
    }

    public function testFileWithIdentifyingContentIsRejectedAndDeleted(): void
    {
        $importId = $this->upload(EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->visit('b', '2026-10-01T09:15', overrides: ['mts_diagram' => 'Muster, Erika']));

        $this->assertFileRejected($importId, EncounterFileRejection::IdentifyingContent, deleted: true);
    }

    public function testHeaderMismatchRejectsTheFileButKeepsItForErrorAnalysis(): void
    {
        $importId = $this->upload(EdVisitFileBuilder::create()
            ->header(\array_slice(EdVisitFileBuilder::headerV1(), 0, -1))
            ->visit('a', '2026-10-01T08:15'));

        $this->assertFileRejected($importId, EncounterFileRejection::HeaderMismatch, deleted: false);
    }

    public function testDifferentKeyCheckValueIsRejected(): void
    {
        $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'), 'registers');

        $importId = $this->upload(EdVisitFileBuilder::create()
            ->manifest('key_check', str_repeat('b', 64))
            ->visit('b', '2026-10-01T09:15'), 'other key');

        $this->assertFileRejected($importId, EncounterFileRejection::KeyCheckMismatch, deleted: false);
    }

    public function testRetiredKeyCheckValueIsAlwaysRejected(): void
    {
        $retired = str_repeat('c', 64);
        $this->connection()->executeStatement(
            "INSERT INTO encounter_key_state (hospital_id, key_generation, active_check_value, retired_check_values, created_at) VALUES (?, 2, NULL, ?, '2026-11-01 10:00:00')",
            [$this->hospital->getId(), json_encode([$retired])],
        );

        $importId = $this->upload(EdVisitFileBuilder::create()->manifest('key_check', $retired)->visit('a', '2026-10-01T08:15'), keyGeneration: 2);

        $this->assertFileRejected($importId, EncounterFileRejection::KeyCheckRetired, deleted: false);
        self::assertNull($this->connection()->fetchOne('SELECT active_check_value FROM encounter_key_state WHERE hospital_id = ?', [$this->hospital->getId()]));
    }

    public function testStaleKeyGenerationFailsTheRunWithoutWriting(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO encounter_key_state (hospital_id, key_generation, created_at) VALUES (?, 2, '2026-11-01 10:00:00')",
            [$this->hospital->getId()],
        );

        // Uploaded while the hospital was still at generation 1.
        $importId = $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'), keyGeneration: 1);

        self::assertSame(ImportStatus::FAILED, $this->import($importId)->getStatus());
        self::assertSame(ImportRunReasonCode::KeyGenerationChanged, $this->lastRunReason($importId));
        self::assertSame(0, $this->encounterCount($importId));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]));
        self::assertNull($this->connection()->fetchOne('SELECT active_check_value FROM encounter_key_state WHERE hospital_id = ?', [$this->hospital->getId()]));
    }

    public function testReprocessAfterAKeyChangeKeepsTheImportsVisits(): void
    {
        $importId = $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15'));
        $this->connection()->executeStatement('UPDATE encounter_key_state SET key_generation = 2 WHERE hospital_id = ?', [$this->hospital->getId()]);

        $this->runEncounterImport($importId);

        self::assertSame(ImportRunReasonCode::KeyGenerationChanged, $this->lastRunReason($importId));
        self::assertSame(1, $this->encounterCount($importId));
    }

    public function testCreatorWithoutUploadGrantCannotRunTheImport(): void
    {
        $outsider = $this->user();
        $importId = $this->uploadEncounterFile($this->hospital, $outsider, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'no grant');

        self::assertSame(ImportRunReasonCode::MissingImportPermission, $this->lastRunReason($importId));
        self::assertSame(0, $this->encounterCount($importId));
    }

    public function testReprocessRemovesAndRecreatesOnlyThisImportsVisits(): void
    {
        $first = $this->upload(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->visit('b', '2026-10-01T09:00', case: 'case-b'), 'first');
        $second = $this->upload(EdVisitFileBuilder::create()
            ->visit('b', '2026-10-01T09:00', case: 'case-b')
            ->visit('b later', '2026-10-01T09:10', case: 'case-b')
            ->visit('c', '2026-10-02T10:00'), 'second');
        $firstBefore = $this->visitUids($first);
        $secondBefore = $this->visitUids($second);
        self::assertCount(2, $secondBefore);
        $this->assertCounts($second, inserted: 2, duplicates: 1, skipped: 0, rejected: 0, suspected: 1);

        $this->runEncounterImport($second);

        self::assertSame(ImportStatus::COMPLETED, $this->import($second)->getStatus());
        self::assertSame(2, $this->import($second)->getRunCount());
        $this->assertCounts($second, inserted: 2, duplicates: 1, skipped: 0, rejected: 0, suspected: 1);
        $secondAfter = $this->visitUids($second);
        self::assertSame(array_keys($secondBefore), array_keys($secondAfter), 'The same visits belong to the import again.');
        self::assertSame([], array_intersect($secondBefore, $secondAfter), 'They were removed and created anew.');
        self::assertSame($firstBefore, $this->visitUids($first), 'Visits of the other import are untouched.');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_suspected_duplicate WHERE import_id = ?', [$second]));
    }

    public function testReprocessOfAFileWithRejectsDoesNotDuplicateRejects(): void
    {
        $importId = $this->upload(EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->visit('b', '2026-10-01T09:15', overrides: ['spo2' => '120']));

        $this->runEncounterImport($importId);

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]));
        self::assertSame(1, $this->encounterCount($importId));
    }

    private function upload(EdVisitFileBuilder $file, string $name = 'encounters', int $keyGeneration = 1): int
    {
        return $this->uploadEncounterFile($this->hospital, $this->uploader, $file->build(), $name, $keyGeneration);
    }

    private function import(int $importId): Import
    {
        $em = $this->entityManager();
        $em->clear();
        $import = $em->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }

    private function assertCounts(int $importId, int $inserted, int $duplicates, int $skipped, int $rejected, int $suspected): void
    {
        $encounterImport = $this->encounterImportOf($importId);
        self::assertSame(
            ['inserted' => $inserted, 'duplicates' => $duplicates, 'skipped' => $skipped, 'rejected' => $rejected, 'suspected' => $suspected],
            [
                'inserted' => $encounterImport->getInserted(),
                'duplicates' => $encounterImport->getDuplicates(),
                'skipped' => $encounterImport->getSkipped(),
                'rejected' => $encounterImport->getRejected(),
                'suspected' => $encounterImport->getSuspectedDuplicates(),
            ],
        );
    }

    private function assertFileRejected(int $importId, EncounterFileRejection $reason, bool $deleted): void
    {
        $encounterImport = $this->encounterImportOf($importId);
        self::assertSame($reason, $encounterImport->getFileRejection());
        self::assertSame($deleted, $encounterImport->isSourceFileDeleted());
        self::assertSame(!$deleted, $this->sourceFileExists($importId));
        self::assertSame(ImportStatus::FAILED, $this->import($importId)->getStatus());
        self::assertSame(ImportRunReasonCode::InvalidFile, $this->lastRunReason($importId));
        self::assertSame(0, $this->encounterCount($importId));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import_reject WHERE import_id = ?', [$importId]));
    }

    private function lastRunReason(int $importId): ?ImportRunReasonCode
    {
        $run = $this->entityManager()->getRepository(ImportRun::class)->findOneBy(['import' => $importId], ['id' => 'DESC']);
        self::assertInstanceOf(ImportRun::class, $run);

        return $run->getReasonCode();
    }

    /**
     * @return array<string, string> fingerprint => visit uid
     */
    private function visitUids(int $importId): array
    {
        /* @var array<string, string> */
        return $this->connection()->fetchAllKeyValue('SELECT visit_fingerprint, visit_uid FROM encounter WHERE import_id = ? ORDER BY visit_fingerprint', [$importId]);
    }

    /**
     * @return \ArrayObject<int, EncountersImported>
     */
    private function recordEvents(): \ArrayObject
    {
        /** @var \ArrayObject<int, EncountersImported> $events */
        $events = new \ArrayObject();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(EncountersImported::class, static function (EncountersImported $event) use ($events): void {
            $events[] = $event;
        });

        return $events;
    }
}
