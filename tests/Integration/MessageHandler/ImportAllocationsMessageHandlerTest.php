<?php

// tests/Integration/MessageHandler/ImportAllocationsMessageHandlerTest.php
declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use App\MessageHandler\ImportAllocationsMessageHandler;
use App\Repository\ImportRepository;
use App\Service\Import\Adapter\InMemoryRejectWriter;
use App\Service\Import\Adapter\InMemoryRowReader;
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
        // --- Arrange: Stammdaten & Hospital ---
        $owner = UserFactory::createOne();
        $state = StateFactory::createOne();
        $da = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $da,
            'owner' => $owner,
        ]);

        // --- Arrange: In-Memory "CSV" (Header + 3 Rows; 1 davon invalid: age=0) ---
        $header = [
            'versorgungsbereich',
            'khs_versorgungsgebiet',
            'krankenhaus',
            'krankenhaus_kurzname',
            'datum',
            'uhrzeit',
            'datum_eintreffzeit',
            'uhrzeit_eintreffzeit',
            'geschlecht',
            'alter',
            'schockraum',
            'herzkatheter',
            'reanimation',
            'beatmet',
            'schwanger',
            'arztbegleitet',
            'transportmittel',
            'datum_erstellungsdatum',
            'uhrzeit_erstellungsdatum',
        ];

        $rows = [
            // 19 Werte:
            ['Leitstelle Test','1',"Test Krankenhaus, Teststraße 1, 12345 Musterstadt",'KH Test','07.01.2025','10:19','07.01.2025','13:14','W','74','S+','H+','R+','B-','Schwanger','N+','Boden','07.01.2025','10:19'],
            ['Leitstelle Test','1',"Test Krankenhaus, Teststraße 1, 12345 Musterstadt",'KH Test','16.02.2025','12:00','16.02.2025','13:01','W','0','','','','B-','','N-','Boden','16.02.2025','12:00'],
            ['Leitstelle Test','1',"Test Krankenhaus, Teststraße 1, 12345 Musterstadt",'KH Test','08.01.2025','01:10','09.01.2025','01:12','M','79','','','','B-','','N-','Boden','08.01.2025','01:10'],
        ];

        $userRef = $this->em->getReference(\App\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Entity\Hospital::class, $hospital->getId());

        // --- Arrange: Import-Entity (filePath nur symbolisch, da In-Memory) ---
        $import = (new Import())
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

        // --- Act: Handler.run() direkt mit In-Memory Adaptern ---
        $summary = $this->handler->run($import, $reader, $rejectWriter);

        // --- Reload Import (Handler setzt Status, rows, usw.) ---
        $fresh = $this->imports->find($import->getId());
        self::assertNotNull($fresh);

        // --- Assert: Summary & Entity-Status ---
        self::assertSame(ImportStatus::COMPLETED, $fresh->getStatus());
        self::assertSame(3, $fresh->getRowCount());
        self::assertSame(2, $fresh->getRowsPassed());
        self::assertSame(1, $fresh->getRowsRejected());

        // Da der InMemoryRejectWriter keinen Dateipfad besitzt, bleibt rejectFilePath null.
        // Wichtig ist: Die inhaltlichen Rejects sind vorhanden:
        self::assertCount(1, $rejectWriter->getRows());

        // Optional: einfache Plausibilitäts-Prüfung der Reject-Meldungen
        $firstReject = $rejectWriter->getRows()[0];
        self::assertArrayHasKey('messages', $firstReject);
        self::assertNotEmpty($firstReject['messages']);
    }
}
