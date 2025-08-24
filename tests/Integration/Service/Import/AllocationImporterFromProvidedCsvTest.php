<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Import;
use App\Entity\User;
use App\Enum\ImportStatus;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\ImportFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use App\Repository\AllocationRepository;
use App\Service\Import\Adapter\CsvRejectWriter;
use App\Service\Import\Adapter\DoctrineAllocationPersister;
use App\Service\Import\Adapter\SplCsvRowReader;
use App\Service\Import\AllocationImporter;
use App\Service\Import\Mapping\AllocationImportFactory;
use App\Service\Import\Mapping\AllocationRowMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration test that runs the full Allocation import pipeline (Reader → Mapper → Validator → Factory → Persister)
 * against the provided sample CSV used in the pipeline tests.
 *
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
    use ResetDatabase; // requires zenstruck/foundry

    private string $fixtureFile = 'allocation_import_sample.csv';
    private string $rejectDir = 'var/tests/rejects';

    private string $fixturePath;
    private string $rejectPath;

    private ValidatorInterface $validator;
    private AllocationRowMapper $mapper;
    private AllocationImportFactory $factory;
    private DoctrineAllocationPersister $persister;
    private LoggerInterface $logger;

    private Import $import;

    protected function setUp(): void
    {
        self::bootKernel();

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $this->fixturePath = $projectDir.'/tests/Fixtures/'.$this->fixtureFile;
        $this->rejectPath = $projectDir.'/'.$this->rejectDir.'/rejects_it_'.date('Ymd_His').'.csv';
        @mkdir(\dirname($this->rejectPath), 0775, true);

        self::assertFileExists($this->fixturePath, 'Fixture CSV missing at '.$this->fixturePath);

        // pull required services from container
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
        $this->mapper = self::getContainer()->get(AllocationRowMapper::class);
        $this->persister = self::getContainer()->get(DoctrineAllocationPersister::class);
        $this->logger = self::getContainer()->get(LoggerInterface::class);

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = UserFactory::createOne();
        $user = $em->getRepository(User::class)->find($user->getId());

        $state = StateFactory::createOne(['name' => 'Test State']);
        $area = DispatchAreaFactory::createOne(['name' => 'Test Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne(['name' => 'Test Hospital', 'state' => $state, 'dispatchArea' => $area]);
        $import = ImportFactory::createOne(['name' => 'Test Import', 'hospital' => $hospital, 'status' => ImportStatus::PENDING]);

        $this->import = $em->getRepository(Import::class)->findOneBy(['id' => $import->getId()]);

        $dispatchRepo = self::getContainer()->get(\App\Repository\DispatchAreaRepository::class);
        $stateRepo = self::getContainer()->get(\App\Repository\StateRepository::class);

        $this->factory = new AllocationImportFactory($dispatchRepo, $stateRepo, $em);

        $this->import = $import;
    }

    public function testImporterRunsOnSampleAndPersists4RowsWrites1Reject(): void
    {
        // Arrange: Reader & RejectWriter bound to this run
        $reader = new SplCsvRowReader(
            new \SplFileObject($this->fixturePath),
        );

        $rejectWriter = new CsvRejectWriter($this->rejectPath);

        $importer = new AllocationImporter(
            validator: $this->validator,
            reader: $reader,
            mapper: $this->mapper,
            factory: $this->factory,
            persister: $this->persister,
            rejectWriter: $rejectWriter,
            logger: $this->logger
        );

        // Warm caches for DispatchArea/State
        $this->factory->warm();

        // Act
        $summary = $importer->import($this->import);

        // Assert summary
        self::assertSame(5, $summary['total'] ?? null);
        self::assertSame(4, $summary['ok'] ?? null);
        self::assertSame(1, $summary['rejected'] ?? null);

        // Assert DB has 4 allocations
        /** @var AllocationRepository $repo */
        $repo = self::getContainer()->get(AllocationRepository::class);
        self::assertSame(4, $repo->count([]), 'Expected 4 persisted allocations');

        // Assert reject file exists & has exactly 1 data row (header + 1)
        self::assertFileExists($this->rejectPath, 'Reject file not written');
        $lines = file($this->rejectPath, FILE_IGNORE_NEW_LINES);

        self::assertIsArray($lines);
        self::assertGreaterThanOrEqual(2, \count($lines), 'Reject CSV should contain header and one data row');
        self::assertCount(2, $lines, 'Reject CSV should contain exactly 1 rejected row plus header');

        // Optional: sanity check first persisted allocation timestamps/transport
        $alloc = $repo->findOneBy([], ['id' => 'ASC']);
        self::assertNotNull($alloc);
        self::assertSame('2025-01-07 10:19', $alloc->getCreatedAt()->format('Y-m-d H:i'));
        self::assertSame('2025-01-07 13:14', $alloc->getArrivalAt()->format('Y-m-d H:i'));
    }
}
