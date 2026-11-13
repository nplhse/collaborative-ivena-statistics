<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Import\EncounterSourceFileRetention;
use App\Encounter\Application\Message\DeleteExpiredEncounterSourceFilesMessage;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Infrastructure\Scheduler\EncounterScheduleContribution;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Encounter upload files are deleted after `app.encounter.source_file_retention_days` (30 in the test
 * environment) counted from the upload (ADR 026, #1049). Every file here is uploaded on
 * 1 October 2026 at 10:00.
 */
#[ResetDatabase]
final class EncounterSourceFileRetentionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private const string UPLOADED_AT = '2026-10-01 10:00:00';

    private Hospital $hospital;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::UPLOADED_AT);
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

    public function testAnAcceptedFileIsKeptAtDay29AndDeletedAtDay31WithItsDate(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'accepted');
        self::assertSame(ImportStatus::COMPLETED, $this->importById($importId)->getStatus());

        self::mockTime('2026-10-30 10:00:00');
        $day29 = $this->retention()->run();

        self::assertSame([], $day29->deleted);
        self::assertTrue($this->sourceFileExists($importId));
        self::assertFalse($this->encounterImportOf($importId)->isSourceFileDeleted());

        self::mockTime('2026-11-01 10:00:00');
        $day31 = $this->retention()->run();

        self::assertSame([$importId], $day31->deleted);
        self::assertFalse($this->sourceFileExists($importId));
        $encounterImport = $this->encounterImportOf($importId);
        self::assertTrue($encounterImport->isSourceFileDeleted());
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00'), $encounterImport->getSourceFileDeletedAt());
        self::assertSame(1, $this->encounterCount($importId), 'Imported encounters stay.');
        self::assertSame(ImportStatus::COMPLETED, $this->importById($importId)->getStatus());

        self::mockTime('2026-11-02 10:00:00');
        self::assertSame([], $this->retention()->run()->deleted, 'A deleted file is not selected again.');
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00'), $this->encounterImportOf($importId)->getSourceFileDeletedAt());
    }

    public function testAFileRejectedForAPossibleProcessingErrorFollowsTheSameRetention(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->declaredRows(2)->build(), 'row count mismatch');
        $encounterImport = $this->encounterImportOf($importId);
        self::assertSame(EncounterFileRejection::RowCountMismatch, $encounterImport->getFileRejection());
        self::assertFalse($encounterImport->isSourceFileDeleted(), 'kept for error analysis');

        self::mockTime('2026-10-30 10:00:00');
        $this->retention()->run();
        self::assertTrue($this->sourceFileExists($importId));

        self::mockTime('2026-11-01 10:00:00');
        $this->retention()->run();
        self::assertFalse($this->sourceFileExists($importId));
        $encounterImport = $this->encounterImportOf($importId);
        self::assertTrue($encounterImport->isSourceFileDeleted());
        self::assertSame(EncounterFileRejection::RowCountMismatch, $encounterImport->getFileRejection());
    }

    public function testAFileWithIdentifyingContentIsDeletedAtOnceWithItsDate(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::rawVendorExport(), 'raw');

        $encounterImport = $this->encounterImportOf($importId);
        self::assertTrue($encounterImport->isSourceFileDeleted());
        self::assertEquals(new \DateTimeImmutable(self::UPLOADED_AT), $encounterImport->getSourceFileDeletedAt());
    }

    public function testAllocationAndClosureFilesAreNotTouched(): void
    {
        $allocation = $this->otherImport(ImportType::ALLOCATION);
        $closure = $this->otherImport(ImportType::CLOSURE);

        self::mockTime('2026-12-01 10:00:00');
        $result = $this->retention()->run();

        self::assertSame([], $result->deleted);
        self::assertSame([], $result->missing);
        self::assertFileExists($allocation);
        self::assertFileExists($closure);
    }

    public function testADryRunDeletesNothing(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'dry run');

        self::mockTime('2026-11-01 10:00:00');
        $result = $this->retention()->run(dryRun: true);

        self::assertSame([$importId], $result->deleted);
        self::assertTrue($this->sourceFileExists($importId));
        self::assertFalse($this->encounterImportOf($importId)->isSourceFileDeleted());
    }

    public function testAnImportWithARunningRunKeepsItsFileUntilTheNextPass(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'running');
        $this->connection()->executeStatement('UPDATE import SET status = ? WHERE id = ?', [ImportStatus::RUNNING->value, $importId]);

        self::mockTime('2026-11-01 10:00:00');

        self::assertSame([], $this->retention()->run()->deleted);
        self::assertTrue($this->sourceFileExists($importId));
    }

    public function testAFileAlreadyGoneIsMarkedAsDeleted(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'gone');
        new Filesystem()->remove($this->projectDir().'/'.$this->importById($importId)->getFilePath());

        self::mockTime('2026-11-01 10:00:00');
        $result = $this->retention()->run();

        self::assertSame([$importId], $result->missing);
        self::assertTrue($this->encounterImportOf($importId)->isSourceFileDeleted());
    }

    public function testTheScheduledJobIsRecordedWithItsCounters(): void
    {
        $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'scheduled');
        self::mockTime('2026-11-01 10:00:00');
        $message = new DeleteExpiredEncounterSourceFilesMessage();
        $recurring = new EncounterScheduleContribution()->recurringMessages()[0];
        $scheduled = new ScheduledStamp(new MessageContext('default', 'encounter', $recurring->getTrigger(), new \DateTimeImmutable('2026-11-01 03:10:00')));

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new Envelope($message, [new ReceivedStamp('scheduler_default'), $scheduled]));

        // The upload's own run is recorded as encounter_import.
        $runs = $this->connection()->fetchAllAssociative("SELECT job, triggered_by, status, metrics FROM job_run WHERE job <> 'encounter_import'");
        self::assertCount(1, $runs);
        self::assertSame(['encounter_source_file_retention', 'scheduler', 'succeeded'], [$runs[0]['job'], $runs[0]['triggered_by'], $runs[0]['status']]);
        $metrics = json_decode((string) $runs[0]['metrics'], true);
        self::assertIsArray($metrics);
        ksort($metrics);
        self::assertSame(['deleted_files' => 1, 'failed_deletions' => 0, 'missing_files' => 0, 'retention_days' => 30], $metrics);
    }

    public function testTheJobRunsDailyAndIsRegisteredForJobRunMonitoring(): void
    {
        $contribution = new EncounterScheduleContribution();

        self::assertSame([DeleteExpiredEncounterSourceFilesMessage::class => 'encounter_source_file_retention'], $contribution->recordedJobs());
        self::assertSame('10 3 * * *', (string) $contribution->recurringMessages()[0]->getTrigger());
        $jobRuns = self::getContainer()->getParameter('app.job_runs');
        self::assertIsArray($jobRuns);
        self::assertSame(1500, $jobRuns['encounter_source_file_retention']['overdue_after_minutes'] ?? null);
    }

    private function otherImport(ImportType $type): string
    {
        $storedPath = sprintf('var/imports/test-encounter/%s-%s.csv', $type->value, bin2hex(random_bytes(6)));
        $absolutePath = $this->projectDir().'/'.$storedPath;
        new Filesystem()->dumpFile($absolutePath, "a;b\n1;2\n");
        $this->encounterFiles[] = $absolutePath;

        $em = $this->entityManager();
        /** @var Hospital $hospital */
        $hospital = $em->getReference(Hospital::class, (int) $this->hospital->getId());
        /** @var User $creator */
        $creator = $em->getReference(User::class, (int) $this->uploader->getId());
        $em->persist(new Import()
            ->setName($type->value)
            ->setHospital($hospital)
            ->setCreatedBy($creator)
            ->setType($type)
            ->setStatus(ImportStatus::COMPLETED)
            ->setFilePath($storedPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(8)
            ->setRunCount(1)
            ->setRunTime(0)
            ->setRowCount(1));
        $em->flush();

        return $absolutePath;
    }

    private function importById(int $importId): Import
    {
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }

    private function retention(): EncounterSourceFileRetention
    {
        $retention = self::getContainer()->get(EncounterSourceFileRetention::class);
        self::assertInstanceOf(EncounterSourceFileRetention::class, $retention);
        $this->entityManager()->clear();

        return $retention;
    }
}
