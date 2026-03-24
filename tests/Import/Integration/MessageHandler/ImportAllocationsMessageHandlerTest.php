<?php

// tests/Integration/MessageHandler/ImportAllocationsMessageHandlerTest.php
declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRowReader;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ImportAllocationsMessageHandlerTest extends KernelTestCase
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
        $owner = UserFactory::createOne(['username' => 'import-handler-owner-'.bin2hex(random_bytes(6))]);
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

        // Act
        $this->handler->run($import, $reader, $rejectWriter);

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

    public function testInvokeWithUnknownImportIdDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->__invoke(new ImportAllocationsMessage(2_147_483_647));
    }

    public function testInvokeWithMissingCsvFileMarksImportFailed(): void
    {
        $owner = UserFactory::createOne(['username' => 'import-missing-'.bin2hex(random_bytes(5))]);
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

        $this->handler->__invoke(new ImportAllocationsMessage($id));

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertSame(ImportStatus::FAILED, $fresh->getStatus());
    }

    public function testInvokeDispatchesImportCompletedAfterSuccessfulFileImport(): void
    {
        $owner = UserFactory::createOne(['username' => 'import-invoke-'.bin2hex(random_bytes(5))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'InvokeEvt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'InvokeEvt KH',
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

        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);

        IndicationRawFactory::createOne(['name' => 'Evt Indication', 'code' => 456, 'hash' => 'a70f5e78cc3ce4b3c3378aeaa0a304a5']);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
        ];

        $rows = [
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '456741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '456 Evt Indication'],
        ];

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
            ->setName('Handler invoke IT')
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

        $id = (int) $import->getId();

        $dispatchedIds = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportCompleted::class, function (object $event) use (&$dispatchedIds): void {
            if ($event instanceof ImportCompleted) {
                $dispatchedIds[] = $event->importId;
            }
        });

        try {
            $this->handler->__invoke(new ImportAllocationsMessage($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        self::assertSame([$id], $dispatchedIds);

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertNotSame(ImportStatus::FAILED, $fresh->getStatus());
    }
}
