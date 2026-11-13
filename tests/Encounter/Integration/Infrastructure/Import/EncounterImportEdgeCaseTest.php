<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Encounter\Application\Contract\DepartmentCatalogInterface;
use App\Encounter\Application\Import\EncounterKeyGenerationChangedException;
use App\Encounter\Application\Import\ImportEncountersDispatcher;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Encounter\Domain\Entity\EncounterSuspectedDuplicate;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Infrastructure\Import\EncounterFileImporter;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Edge cases of the encounter import (#1048): failing preconditions of a run, the key generation check
 * inside the writing commit, department lookup and the suspected-duplicate mapping.
 */
#[ResetDatabase]
final class EncounterImportEdgeCaseTest extends KernelTestCase
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

    public function testDepartmentIsFoundByNameIgnoringCaseButNotWhenUnknownOrAmbiguous(): void
    {
        $createdBy = UserFactory::createOne();
        $cardiology = DepartmentFactory::createOne(['name' => 'Kardiologie', 'createdBy' => $createdBy]);
        DepartmentFactory::createOne(['name' => 'Chirurgie', 'createdBy' => $createdBy]);
        DepartmentFactory::createOne(['name' => 'chirurgie', 'createdBy' => $createdBy]);
        $catalog = self::getContainer()->get(DepartmentCatalogInterface::class);
        self::assertInstanceOf(DepartmentCatalogInterface::class, $catalog);

        self::assertSame($cardiology->getId(), $catalog->findByName(' KARDIOLOGIE ')?->getId());
        self::assertNull($catalog->findByName('Raumfahrtmedizin'));
        self::assertNull($catalog->findByName('Chirurgie'), 'A name of two catalog entries is not a unique reference.');
    }

    public function testAdmittingDepartmentIsStoredOnTheVisit(): void
    {
        $department = DepartmentFactory::createOne(['name' => 'Innere Medizin', 'createdBy' => UserFactory::createOne()]);

        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15', overrides: ['admitting_department' => 'innere medizin'])
            ->build(), 'with department');

        self::assertSame($department->getId(), (int) $this->connection()->fetchOne('SELECT admitting_department_id FROM encounter WHERE import_id = ?', [$importId]));
    }

    public function testKeyGenerationIsCheckedAgainInTheWritingCommit(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'stale');
        $this->connection()->executeStatement('DELETE FROM encounter WHERE import_id = ?', [$importId]);
        // A key change between the check before the run and the commit (#1050).
        $this->connection()->executeStatement('UPDATE encounter_key_state SET key_generation = 2 WHERE hospital_id = ?', [$this->hospital->getId()]);

        $em = $this->entityManager();
        $em->clear();
        $import = $em->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);
        $encounterImport = $em->getRepository(EncounterImport::class)->findOneBy(['import' => $importId]);
        self::assertInstanceOf(EncounterImport::class, $encounterImport);
        $importer = self::getContainer()->get(EncounterFileImporter::class);
        self::assertInstanceOf(EncounterFileImporter::class, $importer);

        try {
            $importer->import($import, $encounterImport, $this->projectDir().'/'.$import->getFilePath());
            self::fail('The stale key generation was not detected.');
        } catch (EncounterKeyGenerationChangedException $e) {
            self::assertStringContainsString('generation 1', $e->getMessage());
            self::assertStringContainsString('generation 2', $e->getMessage());
        }

        self::assertSame(0, $this->encounterCount($importId));
    }

    public function testKeyChangeInProgressStopsTheWritingCommit(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'during change');
        $this->connection()->executeStatement('DELETE FROM encounter WHERE import_id = ?', [$importId]);
        $this->connection()->executeStatement('UPDATE encounter_key_state SET key_change_in_progress = true WHERE hospital_id = ?', [$this->hospital->getId()]);

        $em = $this->entityManager();
        $em->clear();
        $import = $em->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);
        $encounterImport = $em->getRepository(EncounterImport::class)->findOneBy(['import' => $importId]);
        self::assertInstanceOf(EncounterImport::class, $encounterImport);
        $importer = self::getContainer()->get(EncounterFileImporter::class);
        self::assertInstanceOf(EncounterFileImporter::class, $importer);

        $this->expectException(EncounterKeyGenerationChangedException::class);
        $this->expectExceptionMessage('with a key change in progress');

        $importer->import($import, $encounterImport, $this->projectDir().'/'.$import->getFilePath());
    }

    public function testMissingSourceFileFailsTheRun(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'file gone');
        $this->removeEncounterFiles();

        $this->runEncounterImport($importId);

        self::assertSame(ImportRunReasonCode::FileNotFound, $this->lastRunReason($importId));
        self::assertSame(1, $this->encounterCount($importId), 'The data of the previous run stays when the run cannot start.');
    }

    public function testEncounterImportWithoutEncounterStateFailsAsTypeMismatch(): void
    {
        $import = ImportFactory::createOne([
            'hospital' => $this->hospital,
            'createdBy' => $this->uploader,
            'type' => ImportType::ENCOUNTER,
            'status' => ImportStatus::PENDING,
            'filePath' => 'var/imports/test-encounter/missing.csv',
        ]);

        $this->runEncounterImport((int) $import->getId());

        self::assertSame(ImportRunReasonCode::TypeMismatch, $this->lastRunReason((int) $import->getId()));
    }

    public function testUnreadableFileFailsTheRunAsProcessingError(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('File permissions do not apply to root.');
        }

        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'unreadable');
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);
        $path = $this->projectDir().'/'.$import->getFilePath();
        chmod($path, 0o000);

        try {
            $this->runEncounterImport($importId);
        } finally {
            chmod($path, 0o644);
        }

        self::assertSame(ImportRunReasonCode::ProcessingError, $this->lastRunReason($importId));
        $this->entityManager()->clear();
        self::assertSame(ImportStatus::FAILED, $this->entityManager()->find(Import::class, $importId)?->getStatus());
    }

    public function testSuspectedDuplicateIsMappedWithBothVisits(): void
    {
        $first = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('x at 08:00', '2026-10-01T08:00', case: 'case-x')->build(), 'first');
        $second = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('x at 08:05', '', case: 'case-x', overrides: ['first_assessment_contact_at' => '', 'ed_admission_at' => '2026-10-01T08:05'])
            ->build(), 'second');

        $this->entityManager()->clear();
        $duplicate = $this->entityManager()->getRepository(EncounterSuspectedDuplicate::class)->findOneBy(['import' => $second]);
        self::assertInstanceOf(EncounterSuspectedDuplicate::class, $duplicate);
        self::assertNotNull($duplicate->getId());
        self::assertSame($second, $duplicate->getImport()->getId());
        self::assertSame($second, $duplicate->getEncounter()->getImport()->getId());
        self::assertSame($first, $duplicate->getExistingEncounter()->getImport()->getId());
        self::assertSame(3, $duplicate->getLineNumber());
        self::assertSame(5, $duplicate->getAnchorDifferenceMinutes());
        self::assertSame(AnchorSource::Admission, $duplicate->getAnchorSource());
        self::assertSame(AnchorSource::Assessment, $duplicate->getExistingAnchorSource());

        $encounterImport = $this->encounterImportOf($second);
        self::assertSame('ed_visit', $encounterImport->getSchema());
        self::assertSame(1, $encounterImport->getSchemaVersion());
        self::assertSame('1', $encounterImport->getProfileVersion());
        self::assertSame('1.0.0', $encounterImport->getToolVersion());
        self::assertNull($encounterImport->getFileRejection());
        self::assertFalse($encounterImport->isSourceFileDeleted());
    }

    public function testDispatcherRefusesMissingImportsAndOtherTypes(): void
    {
        $dispatcher = self::getContainer()->get(ImportEncountersDispatcher::class);
        self::assertInstanceOf(ImportEncountersDispatcher::class, $dispatcher);
        $allocationImport = ImportFactory::createOne(['hospital' => $this->hospital, 'createdBy' => $this->uploader, 'type' => ImportType::ALLOCATION]);

        $failures = [];
        foreach ([999_999, (int) $allocationImport->getId()] as $importId) {
            try {
                $dispatcher->dispatch($importId, ImportRunTrigger::Console);
            } catch (\Throwable $e) {
                $failures[] = $e::class;
            }
        }

        self::assertSame([ImportNotFoundException::class, ImportTypeMismatchException::class], $failures);
    }

    private function lastRunReason(int $importId): ?ImportRunReasonCode
    {
        $this->entityManager()->clear();
        $run = $this->entityManager()->getRepository(ImportRun::class)->findOneBy(['import' => $importId], ['id' => 'DESC']);
        self::assertInstanceOf(ImportRun::class, $run);

        return $run->getReasonCode();
    }
}
