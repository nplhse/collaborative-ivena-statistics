<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Application\MessageHandler\RebuildAllocationStatsProjectionHandler;
use App\Statistics\Infrastructure\Entity\ProjectionHospitalDimension;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportDeletionServiceTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ImportRepository $imports;
    private ImportDeletionService $deletionService;
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->imports = $container->get(ImportRepository::class);
        $this->deletionService = $container->get(ImportDeletionService::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testDeleteRemovesImportRelatedDataAndFiles(): void
    {
        ['import' => $import, 'csvPath' => $csvPath, 'importId' => $importId] = $this->arrangeImportWithAllocation();

        try {
            self::assertFileExists($csvPath);
            self::assertSame(1, $this->countAllocationsForImport($importId));
            self::assertSame(1, $this->countProjectionRowsForImport($importId));

            $run = new ImportBatchRun(['reason' => 'test-delete']);
            $run->addItem(new ImportBatchRunItem($importId, $import->getName()));
            $this->em->persist($run);
            $this->em->flush();

            $this->deletionService->delete($import);

            self::assertNull($this->imports->find($importId));
            self::assertSame(0, $this->countAllocationsForImport($importId));
            self::assertSame(0, $this->countProjectionRowsForImport($importId));
            self::assertSame(0, $this->countBatchRunItemsForImport($importId));
            self::assertFileDoesNotExist($csvPath);
        } finally {
            if (\is_file($csvPath)) {
                @unlink($csvPath);
            }
        }
    }

    public function testDeleteLastImportRefreshesMaterializedViews(): void
    {
        ['import' => $import, 'csvPath' => $csvPath, 'importId' => $importId, 'hospitalId' => $hospitalId] = $this->arrangeImportWithAllocation();

        try {
            self::assertInstanceOf(
                ProjectionHospitalDimension::class,
                $this->em->find(ProjectionHospitalDimension::class, $hospitalId),
            );

            $this->deletionService->delete($import);

            $this->em->clear();
            self::assertNull($this->em->find(ProjectionHospitalDimension::class, $hospitalId));
        } finally {
            if (\is_file($csvPath)) {
                @unlink($csvPath);
            }
        }
    }

    public function testDeleteImportKeepsHospitalInMaterializedViewsWhenOtherImportsExist(): void
    {
        ['import' => $firstImport, 'hospitalId' => $hospitalId] = $this->arrangeImportWithAllocation('DeleteKeepA');
        $secondImport = $this->arrangeSecondImportForSameHospital($firstImport);

        try {
            $this->deletionService->delete($firstImport);

            $this->em->clear();
            self::assertInstanceOf(
                ProjectionHospitalDimension::class,
                $this->em->find(ProjectionHospitalDimension::class, $hospitalId),
            );
            self::assertNotNull($this->imports->find((int) $secondImport->getId()));
        } finally {
            $csvPath = $secondImport->getFilePath();
            if (\is_string($csvPath) && '' !== $csvPath && \is_file($csvPath)) {
                @unlink($csvPath);
            }
        }
    }

    /**
     * @return array{import: Import, importId: int, csvPath: string, hospitalId: int}
     */
    private function arrangeImportWithAllocation(string $namePrefix = 'DeleteService'): array
    {
        UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $csvPath = sys_get_temp_dir().'/ivena-import-delete-'.bin2hex(random_bytes(8)).'.csv';
        file_put_contents($csvPath, "header1;header2\nvalue1;value2\n");

        $importProxy = ImportFactory::createOne([
            'name' => $namePrefix.' IT',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => $csvPath,
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => (int) filesize($csvPath),
            'rowCount' => 1,
            'rowsPassed' => 1,
            'rowsRejected' => 0,
            'runCount' => 1,
            'runTime' => 50,
        ]);

        SpecialityFactory::createOne(['name' => 'Delete Speciality']);
        DepartmentFactory::createOne(['name' => 'Delete Department']);
        AssignmentFactory::createOne(['name' => 'Delete Assignment']);
        OccasionFactory::createOne(['name' => 'Delete Occasion']);
        InfectionFactory::createOne(['name' => 'Delete Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Delete Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Delete Normalized']);

        AllocationFactory::createOne([
            'import' => $importProxy,
            'hospital' => $hospital,
            'dispatchArea' => $dispatch,
            'state' => $state,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);

        $importId = (int) $importProxy->getId();
        self::getContainer()->get(OverviewMaterializedViewsInstaller::class)->ensureInstalled();
        self::getContainer()->get(RebuildAllocationStatsProjectionHandler::class)
            ->__invoke(new RebuildAllocationStatsProjection($importId));

        /** @var Import $import */
        $import = $this->imports->find($importId);

        return ['import' => $import, 'importId' => $importId, 'csvPath' => $csvPath, 'hospitalId' => (int) $hospital->getId()];
    }

    private function arrangeSecondImportForSameHospital(Import $firstImport): Import
    {
        $hospital = $firstImport->getHospital();
        self::assertNotNull($hospital);

        $csvPath = sys_get_temp_dir().'/ivena-import-delete-'.bin2hex(random_bytes(8)).'.csv';
        file_put_contents($csvPath, "header1;header2\nvalue1;value2\n");

        $importProxy = ImportFactory::createOne([
            'name' => 'DeleteKeepB IT',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => $csvPath,
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => (int) filesize($csvPath),
            'rowCount' => 1,
            'rowsPassed' => 1,
            'rowsRejected' => 0,
            'runCount' => 1,
            'runTime' => 50,
        ]);

        AllocationFactory::createOne([
            'import' => $importProxy,
            'hospital' => $hospital,
            'dispatchArea' => $hospital->getDispatchArea(),
            'state' => $hospital->getState(),
        ]);

        $importId = (int) $importProxy->getId();
        self::getContainer()->get(RebuildAllocationStatsProjectionHandler::class)
            ->__invoke(new RebuildAllocationStatsProjection($importId));

        /** @var Import $import */
        $import = $this->imports->find($importId);
        self::assertInstanceOf(Import::class, $import);

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

    private function countProjectionRowsForImport(int $importId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = :importId',
            ['importId' => $importId],
        );
    }

    private function countBatchRunItemsForImport(int $importId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM import_batch_run_item WHERE import_id = :importId',
            ['importId' => $importId],
        );
    }
}
