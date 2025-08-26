<?php

// tests/Integration/MessageHandler/ImportAllocationsMessageHandlerTest.php
declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Allocation;
use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use App\Message\ImportAllocationsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ImportAllocationsMessageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MessageBusInterface $bus;
    private string $tmpDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->bus = $container->get(MessageBusInterface::class);

        $this->tmpDir = sys_get_temp_dir().'/import_msg_'.bin2hex(random_bytes(4));
        @mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function testHandlerRunsImportUpdatesImportEntityAndWritesRejects(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne(['name' => 'Leitstelle Test']);
        $hospital = HospitalFactory::createOne(['name' => 'Testkrankenhaus Musterstadt']);

        // CSV: 2 ok + 1 invalid (Age=0 → DTO-Constraint)
        $csv = $this->tmpDir.'/sample.csv';
        file_put_contents($csv, implode("\n", [
            'Versorgungsbereich;KHS-Versorgungsgebiet;Krankenhaus;Krankenhaus-Kurzname;Datum;Uhrzeit;Datum (Eintreffzeit);Uhrzeit (Eintreffzeit);Geschlecht;Alter;Schockraum;Herzkatheter;Reanimation;Beatmet;Schwanger;Arztbegleitet;Transportmittel;Datum (Erstellungsdatum);Uhrzeit (Erstellungsdatum)',
            'Leitstelle Test;1;'.$hospital->getName().';KH Test;07.01.2025;10:19;07.01.2025;13:14;W;74;S+;H+;R+;B-;;N-;Boden;07.01.2025;10:19',
            'Leitstelle Test;1;'.$hospital->getName().';KH Test;02.03.2025;15:09;02.03.2025;16:43;D;34;S-;;;B-;;N-;Boden;02.03.2025;15:09',
            'Leitstelle Test;1;'.$hospital->getName().';KH Test;16.02.2025;12:00;16.02.2025;13:01;W;0;;;;B-;;N-;Boden;16.02.2025;12:00',
        ]));

        $userRef = $this->em->getReference(\App\Entity\User::class, $user->getId());
        $hospitalRef = $this->em->getReference(\App\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Msg Import')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setStatus(ImportStatus::PENDING)
            ->setType(ImportType::ALLOCATION)
            ->setFilePath($csv)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(filesize($csv) ?: 0)
            ->setRowCount(3)
            ->setRunCount(0)
            ->setRunTime(0);

        $this->em->persist($import);
        $this->em->flush();

        // Act
        $this->bus->dispatch(new ImportAllocationsMessage($import->getId()));

        $this->em->clear();
        /** @var Import $freshImport */
        $freshImport = $this->em->getRepository(Import::class)->find($import->getId());
        self::assertNotNull($freshImport, 'Import not found after handler');

        // Assert
        self::assertSame(ImportStatus::COMPLETED, $freshImport->getStatus(), 'Import status should be Finished');
        self::assertSame(3, $freshImport->getRowCount());
        self::assertSame(2, $freshImport->getRowsPassed());
        self::assertSame(1, $freshImport->getRowsRejected());
        self::assertGreaterThan(0, $freshImport->getRunCount());
        self::assertGreaterThan(0, $freshImport->getRunTime());

        $rejectPath = $freshImport->getRejectFilePath();
        self::assertNotNull($rejectPath, 'Reject path should be set when there are rejects');
        self::assertFileExists($rejectPath);

        $ok = $this->countAllocationsForImportId((int) $freshImport->getId());
        self::assertSame(2, $ok, 'Expected 2 persisted allocations');
    }

    private function countAllocationsForImportId(int $importId): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Allocation::class, 'a')
            ->andWhere('a.import = :imp')
            ->setParameter('imp', $importId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
