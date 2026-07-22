<?php

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
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRowReader;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

final class ImportAllocationsMessageHandlerRunTest extends ImportAllocationsMessageHandlerTestCase
{
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
}
