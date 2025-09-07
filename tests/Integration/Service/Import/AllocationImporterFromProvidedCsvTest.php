<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Import;
use App\Entity\User;
use App\Enum\ImportStatus;
use App\Factory\DepartmentFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\ImportFactory;
use App\Factory\SpecialityFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use App\Repository\AllocationRepository;
use App\Service\Import\Adapter\DoctrineAllocationPersister;
use App\Service\Import\Adapter\SplCsvRejectWriter;
use App\Service\Import\Adapter\SplCsvRowReader;
use App\Service\Import\AllocationImporter;
use App\Service\Import\Mapping\AllocationImportFactory;
use App\Service\Import\Mapping\AllocationRowMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Expectations for the sample file (5 rows):
 * - Row 1: valid
 * - Row 2: valid (gender D → X)
 * - Row 3: invalid age (0) → rejected
 * - Row 4: valid (cross-day arrival)
 * - Row 5: valid (createdAt prefers Erstellungsdatum)
 *
 * Summary: total=5, ok=4, rejected=1
 */
final class AllocationImporterFromProvidedCsvTest extends KernelTestCase
{
    use ResetDatabase;

    private string $fixtureFile = 'allocation_import_sample.csv';
    private string $rejectDir = 'var/tests/rejects';

    private string $fixturePath;
    private string $rejectPath;

    private ValidatorInterface $validator;
    private AllocationRowMapper $mapper;
    private AllocationImportFactory $factory;
    private DoctrineAllocationPersister $persister;
    private LoggerInterface $logger;
    private Filesystem $fs;

    private Import $import;

    protected function setUp(): void
    {
        self::bootKernel();

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $this->fixturePath = $projectDir.'/tests/Fixtures/'.$this->fixtureFile;
        $this->rejectPath = $projectDir.'/'.$this->rejectDir.'/rejects_it_'.date('Ymd_His').'.csv';

        @mkdir(\dirname($this->rejectPath), 0775, true);
        self::assertFileExists($this->fixturePath, 'Fixture CSV missing at '.$this->fixturePath);

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
        $this->mapper = self::getContainer()->get(AllocationRowMapper::class);
        $this->persister = self::getContainer()->get(DoctrineAllocationPersister::class);
        $this->logger = self::getContainer()->get(LoggerInterface::class);
        $this->fs = self::getContainer()->get(Filesystem::class);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = UserFactory::createOne();
        $em->getRepository(User::class)->find($user->getId());

        $state = StateFactory::createOne(['name' => 'Test State']);
        $area = DispatchAreaFactory::createOne(['name' => 'Test Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Test Hospital', 'state' => $state, 'dispatchArea' => $area]);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        $importFA = ImportFactory::createOne([
            'name' => 'Test Import',
            'hospital' => $hospital,
            'status' => ImportStatus::PENDING,
        ]);

        /** @var Import $managedImport */
        $managedImport = $em->getRepository(Import::class)->find($importFA->getId());
        $this->import = $managedImport;

        $this->factory = self::getContainer()->get(AllocationImportFactory::class);
    }

    public function testImporterRunsOnSampleAndPersists4RowsWrites1Reject(): void
    {
        // Arrange
        $reader = new SplCsvRowReader(
            new \SplFileObject($this->fixturePath, 'r'),
            inputEncoding: 'UTF-8',
            delimiter: ';',
            enclosure: '"',
            escape: '\\',
        );

        $rejectWriter = new SplCsvRejectWriter(
            absolutePath: $this->rejectPath,
            filesystem: $this->fs,
            delimiter: ';',
            enclosure: "\0",
            escape: '\\'
        );

        $importer = new AllocationImporter(
            validator: $this->validator,
            reader: $reader,
            mapper: $this->mapper,
            factory: $this->factory,
            persister: $this->persister,
            rejectWriter: $rejectWriter,
            logger: $this->logger
        );

        $this->factory->warm();

        // Act
        $summary = $importer->import($this->import);

        // Assert summary
        self::assertSame(5, $summary['total'] ?? null);
        self::assertSame(4, $summary['ok'] ?? null);
        self::assertSame(1, $summary['rejected'] ?? null);

        /** @var AllocationRepository $repo */
        $repo = self::getContainer()->get(AllocationRepository::class);
        self::assertSame(4, $repo->count([]), 'Expected 4 persisted allocations');

        self::assertFileExists($this->rejectPath, 'Reject file not written');
        $lines = file($this->rejectPath, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines, 'Reject CSV should contain header + exactly 1 rejected row');

        $alloc = $repo->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($alloc);
        self::assertSame('2025-01-07 10:19', $alloc->getCreatedAt()->format('Y-m-d H:i'));
        self::assertSame('2025-01-07 13:14', $alloc->getArrivalAt()->format('Y-m-d H:i'));
    }
}
