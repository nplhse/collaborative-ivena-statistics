<?php

// tests/Integration/Service/Import/AllocationImportFeatureTest.php
declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Allocation;
use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use App\Service\Import\Adapter\CsvRejectWriter;
use App\Service\Import\Adapter\DoctrineAllocationPersister;
use App\Service\Import\Adapter\SplCsvRowReader;
use App\Service\Import\AllocationImporter;
use App\Service\Import\Mapping\AllocationImportFactory;
use App\Service\Import\Mapping\AllocationRowMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AllocationImportFeatureTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $tmpDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tmpDir = sys_get_temp_dir().'/alloc_import_'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
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

    public function testFullImportPipelinePersistsEntitiesAndWritesRejects(): void
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Leitstelle Test']);
        $hospital = HospitalFactory::createOne(['name' => 'Testkrankenhaus Musterstadt']);

        $userRef = $this->em->getReference(\App\Entity\User::class, $user->getId());
        $hospitalRef = $this->em->getReference(\App\Entity\Hospital::class, $hospital->getId());

        // CSV (Header + 3 rows: 2 ok, 1 reject → invalid age=0)
        $csvPath = $this->tmpDir.'/sample.csv';
        file_put_contents($csvPath, implode("\n", [
            'Versorgungsbereich;KHS-Versorgungsgebiet;Krankenhaus;Krankenhaus-Kurzname;Datum;Uhrzeit;Datum (Eintreffzeit);Uhrzeit (Eintreffzeit);Geschlecht;Alter;Schockraum;Herzkatheter;Reanimation;Beatmet;Schwanger;Arztbegleitet;Transportmittel;Datum (Erstellungsdatum);Uhrzeit (Erstellungsdatum)',
            'Leitstelle Test;1;'.$hospital->getName().';KH Test;07.01.2025;10:19;07.01.2025;13:14;W;74;S+;H+;R+;B-;;N-;Boden;07.01.2025;10:19',
            'Leitstelle Test;1;'.$hospital->getName().';KH Test;02.03.2025;15:09;02.03.2025;16:43;D;34;S-;;;B-;;N-;Boden;02.03.2025;15:09',
            'Leitstelle Test;1;'.$hospital->getName().';KH Test;16.02.2025;12:00;16.02.2025;13:01;W;0;;;;B-;;N-;Boden;16.02.2025;12:00',
        ]));

        $import = new Import()
            ->setName('Integration Import')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setStatus(ImportStatus::PENDING)
            ->setType(ImportType::ALLOCATION)
            ->setFilePath($csvPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(filesize($csvPath) ?: 0)
            ->setRowCount(3)
            ->setRunCount(0)
            ->setRunTime(0);

        $this->em->persist($import);
        $this->em->flush();

        $validator = static::getContainer()->get(ValidatorInterface::class);
        $mapper = static::getContainer()->get(AllocationRowMapper::class);
        $factory = static::getContainer()->get(AllocationImportFactory::class);
        $persister = static::getContainer()->get(DoctrineAllocationPersister::class);

        $reader = new SplCsvRowReader(
            new \SplFileObject($csvPath, 'r'),
            delimiter: ';',
            enclosure: "\0",
            escape: '\\',
            inputEncoding: 'UTF-8'
        );

        $rejectPath = $this->tmpDir.'/rejects.csv';
        $rejectWriter = new CsvRejectWriter($rejectPath);

        $importer = new AllocationImporter(
            $validator,
            $reader,
            $mapper,
            $factory,
            $persister,
            $rejectWriter,
            new NullLogger()
        );

        // Act
        $result = $importer->import($import);

        // Assert
        self::assertSame(['total' => 3, 'ok' => 2, 'rejected' => 1], $result);

        // Reject-File exists, has 1 row excluding header
        self::assertFileExists($rejectPath);
        $rejectLines = file($rejectPath, FILE_IGNORE_NEW_LINES);
        self::assertNotFalse($rejectLines);
        self::assertGreaterThanOrEqual(2, count($rejectLines));       // Header + >=1 Zeile
        self::assertSame(1, count($rejectLines) - 1, 'Expected exactly 1 rejected data line');

        $countOk = $this->countAllocationsForImportId((int) $import->getId());
        self::assertSame(2, $countOk, 'Expected 2 persisted allocations');

        $one = $this->findOneAllocationForImportId((int) $import->getId());
        self::assertInstanceOf(Allocation::class, $one);
        self::assertContains($one->getGender()->value, ['M', 'F', 'X']);
        self::assertNotNull($one->getCreatedAt());
        self::assertNotNull($one->getArrivalAt());
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

    private function findOneAllocationForImportId(int $importId): ?Allocation
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Allocation::class, 'a')
            ->andWhere('a.import = :imp')
            ->setParameter('imp', $importId)
            ->setMaxResults(1);

        /** @var ?Allocation $one */
        $one = $qb->getQuery()->getOneOrNullResult();

        return $one;
    }
}
