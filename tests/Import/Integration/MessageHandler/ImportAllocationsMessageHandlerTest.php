<?php

// tests/Integration/MessageHandler/ImportAllocationsMessageHandlerTest.php
declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assessment;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRowReader;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ImportAllocationsMessageHandlerTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;
    private ImportRepository $imports;
    private ImportAllocationsMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        $this->em = $c->get(EntityManagerInterface::class);
        $this->imports = $c->get(ImportRepository::class);
        $this->handler = $c->get(ImportAllocationsMessageHandler::class);
    }

    public function testHandlerRunsImportUpdatesImportEntityAndTracksRejectsInMemory(): void
    {
        // Arrange
        $owner = UserFactory::createOne(['username' => 'import-handler-owner']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        AssignmentFactory::createOne(['name' => 'Patient']);
        AssignmentFactory::createOne(['name' => 'RD']);
        AssignmentFactory::createOne(['name' => 'ZLST']);

        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);

        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);

        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);

        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
        ];

        $rows = [
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '123 Test Indication'],
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '02.03.2025', '15:09', '02.03.2025', '16:43', 'D', '34', 'S-', '', '', 'B-', '', 'N-', 'Boden', '02.03.2025', '15:09', '123341', 'Innere Medizin', 'Kardiologie', 'Ja', 'Öffentlicher Raum', 'RD', 'Keine', '123 Test Indication'],
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '16.02.2025', '12:00', '16.02.2025', '13:01', 'W', '0', '', '', '', 'B-', '', 'N-', 'Boden', '16.02.2025', '12:00', '123001', 'Innere Medizin', 'Kardiologie', 'Ja', 'Häuslicher Einsatz', 'Patient', 'V.a. COVID', '123 Test Indication'],
        ];

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Handler IT (in-memory)')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath('in-memory://allocations.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(0)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $reader = new InMemoryRowReader($header, $rows);
        $rejectWriter = new InMemoryRejectWriter();

        $this->em->persist($import);
        $this->em->flush();

        $allocationAuditsBefore = $this->countAuditEntriesForEntityClass(Allocation::class);

        // Act
        $this->handler->run($import, $reader, $rejectWriter);

        self::assertGreaterThan($allocationAuditsBefore, $this->countAuditEntriesForEntityClass(Allocation::class));

        $fresh = $this->imports->find($import->getId());
        self::assertNotNull($fresh);

        // Assert
        self::assertSame(ImportStatus::PARTIAL, $fresh->getStatus());
        self::assertSame(3, $fresh->getRowCount());
        self::assertSame(2, $fresh->getRowsPassed());
        self::assertSame(1, $fresh->getRowsRejected());
        self::assertSame(1, $rejectWriter->getCount());

        $firstReject = $rejectWriter->all()[0];
        self::assertArrayHasKey('messages', $firstReject);
        self::assertNotEmpty($firstReject['messages']);
    }

    public function testRunDeduplicatesOverlappingAllocationsAndRecordsStats(): void
    {
        $owner = UserFactory::createOne(['username' => 'import-dedup']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $speciality = SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        $department = DepartmentFactory::createOne(['name' => 'Kardiologie']);
        $assignment = AssignmentFactory::createOne(['name' => 'Patient']);
        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        InfectionFactory::createOne(['name' => 'Noro']);
        $indicationRaw = IndicationRawFactory::createOne([
            'name' => 'Test Indication',
            'code' => 123,
            'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4',
        ]);

        $olderImport = ImportFactory::createOne([
            'name' => 'Older overlapping import',
            'hospital' => $hospital,
            'createdBy' => $owner,
            'createdAt' => new \DateTimeImmutable('2024-01-01 10:00:00'),
        ]);

        AllocationFactory::createOne([
            'import' => $olderImport,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'speciality' => $speciality,
            'department' => $department,
            'assignment' => $assignment,
            'indicationRaw' => $indicationRaw,
            'caseIdHash' => null,
            'gender' => AllocationGender::FEMALE,
            'age' => 74,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'createdAt' => new \DateTimeImmutable('2025-01-07 10:19:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-01-07 13:14:00'),
        ]);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
        ];

        $rows = [
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '123 Test Indication'],
        ];

        $import = $this->createInMemoryImport($owner, $hospital);
        $reader = new InMemoryRowReader($header, $rows);
        $rejectWriter = new InMemoryRejectWriter();

        $this->handler->run($import, $reader, $rejectWriter);

        $fresh = $this->imports->find($import->getId());
        self::assertNotNull($fresh);
        self::assertSame(ImportStatus::COMPLETED, $fresh->getStatus());
        self::assertSame(1, $fresh->getRowsPassed());
        self::assertSame(0, $fresh->getRowsRejected());
        self::assertSame(1, $fresh->getRowsDeduplicated());
        self::assertSame(0, $fresh->getRowsDeduplicatedDiscarded());
        self::assertSame(1, $fresh->getRowsDeduplicatedReplaced());

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM allocation WHERE hospital_id = :hospitalId',
            ['hospitalId' => $hospital->getId()],
        ));
    }

    public function testImportWithPlaceholderAbcdColumnsDoesNotPersistAssessmentOrAudit(): void
    {
        $owner = UserFactory::createOne(['username' => 'import-abcd-empty']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        AssignmentFactory::createOne(['name' => 'Patient']);
        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        InfectionFactory::createOne(['name' => 'Noro']);
        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text', 'Airway', 'Breathing', 'Circulation', 'Disability',
        ];

        $rows = [
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '123 Test Indication', 'A-', 'B-', 'C-', 'D-'],
        ];

        $import = $this->createInMemoryImport($owner, $hospital);
        $reader = new InMemoryRowReader($header, $rows);
        $rejectWriter = new InMemoryRejectWriter();

        $assessmentsBefore = $this->countAssessments();
        $assessmentAuditsBefore = $this->countAuditEntriesForEntityClass(Assessment::class);

        $this->handler->run($import, $reader, $rejectWriter);

        self::assertSame($assessmentsBefore, $this->countAssessments());
        self::assertSame($assessmentAuditsBefore, $this->countAuditEntriesForEntityClass(Assessment::class));
    }

    public function testImportWithValidAbcdColumnsPersistsAssessment(): void
    {
        $owner = UserFactory::createOne(['username' => 'import-abcd-valid']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        AssignmentFactory::createOne(['name' => 'Patient']);
        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        InfectionFactory::createOne(['name' => 'Noro']);
        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text', 'Airway', 'Breathing', 'Circulation', 'Disability',
        ];

        $rows = [
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '123 Test Indication', 'A-Frei', 'B-Spontan', 'C-Stabil', 'D-Wach'],
        ];

        $import = $this->createInMemoryImport($owner, $hospital);
        $reader = new InMemoryRowReader($header, $rows);
        $rejectWriter = new InMemoryRejectWriter();

        $assessmentsBefore = $this->countAssessments();
        $importId = (int) $import->getId();

        $this->handler->run($import, $reader, $rejectWriter);

        self::assertSame($assessmentsBefore + 1, $this->countAssessments());

        $this->em->clear();
        $allocation = $this->em->getRepository(Allocation::class)->findOneBy(['import' => $importId]);
        self::assertNotNull($allocation);
        self::assertNotNull($allocation->getAssessment());
    }

    public function testInvokeWithUnknownImportIdDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->__invoke(new ImportAllocationsMessage(2_147_483_647));
    }

    public function testInvokeWithMissingCsvFileMarksImportFailed(): void
    {
        $owner = UserFactory::createOne(['username' => 'import-missing']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'MissingCsv', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'MissingCsv KH',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $missingPath = sys_get_temp_dir().'/ivena-import-missing-'.bin2hex(random_bytes(8)).'.csv';

        $import = new Import()
            ->setName('Missing file IT')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($missingPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(0)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        $id = (int) $import->getId();
        self::assertGreaterThan(0, $id);

        $failedIds = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportFailed::class, function (object $event) use (&$failedIds): void {
            if ($event instanceof ImportFailed) {
                $failedIds[] = $event->importId;
            }
        });

        $this->handler->__invoke(new ImportAllocationsMessage($id));

        self::assertSame([$id], $failedIds);

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertSame(ImportStatus::FAILED, $fresh->getStatus());
    }

    public function testDispatchImportOutcomeDispatchesImportCompletedForFinalImportStatus(): void
    {
        $import = $this->createPersistedImport(ImportStatus::PARTIAL, rowCount: 3, rowsPassed: 2, rowsRejected: 1);

        $dispatchedIds = [];
        $failedIds = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportCompleted::class, function (object $event) use (&$dispatchedIds): void {
            if ($event instanceof ImportCompleted) {
                $dispatchedIds[] = $event->importId;
            }
        });
        $dispatcher->addListener(ImportFailed::class, function (object $event) use (&$failedIds): void {
            if ($event instanceof ImportFailed) {
                $failedIds[] = $event->importId;
            }
        });

        $reflection = new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'dispatchImportOutcome');
        $reflection->invoke($this->handler, (int) $import->getId());

        self::assertSame([], $failedIds);
        self::assertSame([(int) $import->getId()], $dispatchedIds);
    }

    public function testDispatchImportOutcomeDispatchesImportFailedForFailedImportStatus(): void
    {
        $import = $this->createPersistedImport(ImportStatus::FAILED);

        $dispatchedIds = [];
        $failedIds = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportCompleted::class, function (object $event) use (&$dispatchedIds): void {
            if ($event instanceof ImportCompleted) {
                $dispatchedIds[] = $event->importId;
            }
        });
        $dispatcher->addListener(ImportFailed::class, function (object $event) use (&$failedIds): void {
            if ($event instanceof ImportFailed) {
                $failedIds[] = $event->importId;
            }
        });

        $reflection = new \ReflectionMethod(ImportAllocationsMessageHandler::class, 'dispatchImportOutcome');
        $reflection->invoke($this->handler, (int) $import->getId(), 'CSV not found');

        self::assertSame([], $dispatchedIds);
        self::assertSame([(int) $import->getId()], $failedIds);
    }

    private function createPersistedImport(
        ImportStatus $status,
        int $rowCount = 0,
        int $rowsPassed = 0,
        int $rowsRejected = 0,
    ): Import {
        $owner = UserFactory::createOne(['username' => 'import-status']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'StatusEvt'.bin2hex(random_bytes(4)), 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Status Hospital '.bin2hex(random_bytes(4)),
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Dispatch status IT')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus($status)
            ->setFilePath('/tmp/unused.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(0)
            ->setRunCount(1)
            ->setRunTime(10)
            ->setRowCount($rowCount)
            ->setRowsPassed($rowsPassed)
            ->setRowsRejected($rowsRejected);

        $this->em->persist($import);
        $this->em->flush();

        return $import;
    }

    public function testReimportWithAssessmentSucceedsAfterCleanup(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport(withAssessment: true);

        try {
            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));
            self::assertGreaterThan(0, $this->countAssessmentsForImport($id));

            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));
            self::assertGreaterThan(0, $this->countAssessmentsForImport($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }
    }

    public function testReimportDeletesPreviousAllocationsBeforeImportingAgain(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();

        try {
            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));

            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));

            $fresh = $this->imports->find($id);
            self::assertNotNull($fresh);
            self::assertSame(2, $fresh->getRunCount());
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }
    }

    public function testDispatchViaMessageBusRunsImportWithSuppressedAllocationAudits(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();

        $allocationAuditsBeforeInvoke = $this->countAuditEntriesForEntityClass(Allocation::class);

        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new ImportAllocationsMessage($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        self::assertSame(
            $allocationAuditsBeforeInvoke,
            $this->countAuditEntriesForEntityClass(Allocation::class),
            'Import via MessageBus must suppress per-row Allocation audit entries (middleware + handler path)',
        );
        self::assertTrue(
            $this->importEntityHasAuditIntent($id, 'import.run.finished'),
            'Expected Import audit metadata to include import.run.finished intent for this import id',
        );

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertNotSame(ImportStatus::FAILED, $fresh->getStatus());
    }

    public function testDispatchViaMessageBusRunsImportWithSuppressedAssessmentAudits(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport(withAssessment: true);

        $assessmentAuditsBeforeInvoke = $this->countAuditEntriesForEntityClass(Assessment::class);

        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new ImportAllocationsMessage($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        self::assertSame(
            $assessmentAuditsBeforeInvoke,
            $this->countAuditEntriesForEntityClass(Assessment::class),
            'Import via MessageBus must suppress per-row Assessment audit entries (middleware + handler path)',
        );
        self::assertGreaterThan(0, $this->countAssessmentsForImport($id));
    }

    public function testDispatchViaMessageBusRunsAllocationImportSampleFixture(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeAllocationImportSampleCsvImport();

        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new ImportAllocationsMessage($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertTrue(
            $fresh->isFinalStatus(),
            sprintf(
                'Expected final import status, got %s',
                null !== ($status = $fresh->getStatus()) ? $status->value : 'null',
            ),
        );
    }

    /**
     * @return array{id: int, csvPath: string}
     */
    private function arrangeAllocationImportSampleCsvImport(): array
    {
        $owner = UserFactory::createOne(['username' => 'import-sample-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'import-sample-creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Patient']);
        AssignmentFactory::createOne(['name' => 'RD']);
        AssignmentFactory::createOne(['name' => 'ZLST']);
        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);
        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $fixturePath = $projectDir.'/tests/Import/Fixtures/allocation_import_sample.csv';
        self::assertFileExists($fixturePath);

        $targetDir = $projectDir.'/var/tests/imports/'.date('Y/m');
        @mkdir($targetDir, 0775, true);
        $csvPath = $targetDir.'/allocation_import_sample_'.bin2hex(random_bytes(4)).'.csv';
        copy($fixturePath, $csvPath);
        $relativePath = ltrim(str_replace('\\', '/', (string) preg_replace('#^'.preg_quote($projectDir, '#').'/?#', '', $csvPath)), '/');

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Allocation import sample fixture')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($relativePath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($csvPath))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return ['id' => (int) $import->getId(), 'csvPath' => $csvPath];
    }

    /**
     * @return array{id: int, csvPath: string}
     */
    private function arrangeSingleRowSuccessfulCsvImport(bool $withAssessment = false): array
    {
        if ($withAssessment) {
            return $this->arrangeAssessmentCsvImport();
        }

        $suffix = bin2hex(random_bytes(5));

        $owner = UserFactory::createOne(['username' => 'import-csv-'.$suffix]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        AssignmentFactory::createOne(['name' => 'Patient']);
        AssignmentFactory::createOne(['name' => 'RD']);
        AssignmentFactory::createOne(['name' => 'ZLST']);

        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);

        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);

        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);

        IndicationRawFactory::createOne([
            'name' => 'Test Indication',
            'code' => 123,
            'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4',
        ]);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
        ];

        $row = ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '123 Test Indication'];

        $rows = [$row];

        $csvPath = sys_get_temp_dir().'/ivena-import-evt-'.bin2hex(random_bytes(8)).'.csv';
        $fh = fopen($csvPath, 'wb');
        self::assertNotFalse($fh);
        $delimiter = ';';
        $enclosure = '"';
        $escape = '\\';
        fputcsv($fh, $header, $delimiter, $enclosure, $escape);
        foreach ($rows as $csvRow) {
            fputcsv($fh, $csvRow, $delimiter, $enclosure, $escape);
        }
        fclose($fh);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Handler invoke IT '.$suffix)
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($csvPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($csvPath))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return ['id' => (int) $import->getId(), 'csvPath' => $csvPath];
    }

    /**
     * @return array{id: int, csvPath: string}
     */
    private function arrangeAssessmentCsvImport(): array
    {
        $suffix = bin2hex(random_bytes(5));

        $owner = UserFactory::createOne(['username' => 'import-csv-'.$suffix]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        AssignmentFactory::createOne(['name' => 'Patient']);
        AssignmentFactory::createOne(['name' => 'RD']);
        AssignmentFactory::createOne(['name' => 'ZLST']);

        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);

        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);

        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);

        IndicationRawFactory::createOne([
            'name' => 'Test Indication',
            'code' => 123,
            'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4',
        ]);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
            'Airway', 'Breathing', 'Circulation', 'Disability',
        ];

        $pzcCol = '123741';
        $pzTextCol = '123 Test Indication';

        $row = ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', $pzcCol, 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', $pzTextCol, 'A-Frei', 'B-Spontan', 'C-Stabil', 'D-Wach'];

        $rows = [$row];

        $csvPath = sys_get_temp_dir().'/ivena-import-evt-'.bin2hex(random_bytes(8)).'.csv';
        $fh = fopen($csvPath, 'wb');
        self::assertNotFalse($fh);
        $delimiter = ';';
        $enclosure = '"';
        $escape = '\\';
        fputcsv($fh, $header, $delimiter, $enclosure, $escape);
        foreach ($rows as $row) {
            fputcsv($fh, $row, $delimiter, $enclosure, $escape);
        }
        fclose($fh);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Handler invoke IT '.$suffix)
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($csvPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($csvPath))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return ['id' => (int) $import->getId(), 'csvPath' => $csvPath];
    }

    private function countAssessments(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Assessment::class, 'a')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countAssessmentsForImport(int $importId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT ass.id)')
            ->from(Allocation::class, 'a')
            ->join('a.assessment', 'ass')
            ->where('a.import = :importId')
            ->setParameter('importId', $importId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createInMemoryImport(
        \App\User\Domain\Entity\User $owner,
        \App\Allocation\Domain\Entity\Hospital $hospital,
    ): Import {
        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Handler IT (in-memory assessment)')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath('in-memory://allocations.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(0)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return $import;
    }

    private function countAllocationsForImport(int $importId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Allocation::class, 'a')
            ->where('a.import = :importId')
            ->setParameter('importId', $importId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countAuditEntriesForEntityClass(string $entityClass): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AuditEntry::class, 'a')
            ->andWhere('a.entityClass = :class')
            ->setParameter('class', $entityClass)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function importEntityHasAuditIntent(int $importId, string $intentName): bool
    {
        /** @var list<AuditEntry> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AuditEntry::class, 'a')
            ->andWhere('a.entityClass = :class')
            ->setParameter('class', Import::class)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(80)
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $meta = $row->getMetadata();
            if (!\is_array($meta)) {
                continue;
            }
            if (($meta['intent'] ?? null) !== $intentName) {
                continue;
            }
            $intentMeta = $meta['intent_metadata'] ?? [];
            if (!\is_array($intentMeta)) {
                continue;
            }
            $rowImportId = $intentMeta['import_id'] ?? null;
            if ((int) $rowImportId === $importId) {
                return true;
            }
        }

        return false;
    }
}
