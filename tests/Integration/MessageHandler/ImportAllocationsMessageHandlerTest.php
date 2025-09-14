<?php

// tests/Integration/MessageHandler/ImportAllocationsMessageHandlerTest.php
declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Factory\DepartmentFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\SpecialityFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use App\MessageHandler\ImportAllocationsMessageHandler;
use App\Repository\ImportRepository;
use App\Tests\Doubles\Service\Import\Adapter\InMemoryRejectWriter;
use App\Tests\Doubles\Service\Import\Adapter\InMemoryRowReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportAllocationsMessageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ImportRepository $imports;
    private ImportAllocationsMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $this->em = $c->get(EntityManagerInterface::class);
        $this->imports = $c->get(ImportRepository::class);
        $this->handler = $c->get(ImportAllocationsMessageHandler::class);
    }

    public function testHandlerRunsImportUpdatesImportEntityAndTracksRejectsInMemory(): void
    {
        // Arrange
        $owner = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Leitstelle Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?',
        ];

        $rows = [
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja'],
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '02.03.2025', '15:09', '02.03.2025', '16:43', 'D', '34', 'S-', '', '', 'B-', '', 'N-', 'Boden', '02.03.2025', '15:09', '123341', 'Innere Medizin', 'Kardiologie', 'Ja'],
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '16.02.2025', '12:00', '16.02.2025', '13:01', 'W', '0', '', '', '', 'B-', '', 'N-', 'Boden', '16.02.2025', '12:00', '123001', 'Innere Medizin', 'Kardiologie', 'Ja'],
        ];

        $userRef = $this->em->getReference(\App\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Entity\Hospital::class, $hospital->getId());

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
        $summary = $this->handler->run($import, $reader, $rejectWriter);

        $fresh = $this->imports->find($import->getId());
        self::assertNotNull($fresh);

        // Assert
        self::assertSame(ImportStatus::COMPLETED, $fresh->getStatus());
        self::assertSame(3, $fresh->getRowCount());
        self::assertSame(2, $fresh->getRowsPassed());
        self::assertSame(1, $fresh->getRowsRejected());
        self::assertSame(1, $rejectWriter->getCount());

        $firstReject = $rejectWriter->all()[0];
        self::assertArrayHasKey('messages', $firstReject);
        self::assertNotEmpty($firstReject['messages']);
    }
}
