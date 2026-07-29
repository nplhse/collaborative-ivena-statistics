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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
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

    public function testDeleteRemovesRelativeImportFilePath(): void
    {
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $filesystem = new Filesystem();
        $relativePath = 'var/imports/delete-relative-'.bin2hex(random_bytes(4)).'.csv';
        $absolutePath = Path::join($projectDir, $relativePath);
        $filesystem->mkdir(\dirname($absolutePath));
        file_put_contents($absolutePath, "header1;header2\nvalue1;value2\n");

        ['import' => $import, 'importId' => $importId] = $this->arrangeImportWithAllocation(
            namePrefix: 'DeleteRelative',
            filePath: $relativePath,
        );

        try {
            self::assertFileExists($absolutePath);

            $this->deletionService->delete($import);

            self::assertNull($this->imports->find($importId));
            self::assertFileDoesNotExist($absolutePath);
        } finally {
            if ($filesystem->exists($absolutePath)) {
                $filesystem->remove($absolutePath);
            }
        }
    }

    public function testDeleteRemovesRejectFile(): void
    {
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $filesystem = new Filesystem();
        $sourceRelativePath = 'var/imports/delete-source-'.bin2hex(random_bytes(8)).'.csv';
        $sourcePath = Path::join($projectDir, $sourceRelativePath);
        $filesystem->mkdir(\dirname($sourcePath));
        file_put_contents($sourcePath, "header1;header2\nvalue1;value2\n");

        $rejectRelativePath = 'var/imports/rejects/delete-reject-'.bin2hex(random_bytes(4)).'.csv';
        $rejectAbsolutePath = Path::join($projectDir, $rejectRelativePath);
        $filesystem->mkdir(\dirname($rejectAbsolutePath));
        file_put_contents($rejectAbsolutePath, "row;reason\n1;invalid\n");

        ['import' => $import, 'importId' => $importId] = $this->arrangeImportWithAllocation(
            namePrefix: 'DeleteReject',
            filePath: $sourceRelativePath,
            rejectFilePath: $rejectRelativePath,
        );

        try {
            self::assertFileExists($rejectAbsolutePath);

            $this->deletionService->delete($import);

            self::assertNull($this->imports->find($importId));
            self::assertFileDoesNotExist($sourcePath);
            self::assertFileDoesNotExist($rejectAbsolutePath);
        } finally {
            if ($filesystem->exists($sourcePath)) {
                $filesystem->remove($sourcePath);
            }
            if ($filesystem->exists($rejectAbsolutePath)) {
                $filesystem->remove($rejectAbsolutePath);
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
            $storedPath = $secondImport->getFilePath();
            if (\is_string($storedPath) && '' !== $storedPath) {
                $absolute = Path::isAbsolute($storedPath)
                    ? $storedPath
                    : Path::join((string) self::getContainer()->getParameter('kernel.project_dir'), $storedPath);
                if (\is_file($absolute)) {
                    @unlink($absolute);
                }
            }
        }
    }

    /**
     * @return array{import: Import, importId: int, csvPath: string, hospitalId: int}
     */
    private function arrangeImportWithAllocation(
        string $namePrefix = 'DeleteService',
        ?string $filePath = null,
        ?string $rejectFilePath = null,
    ): array {
        UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        if (null === $filePath) {
            ['absolutePath' => $csvPath, 'storedPath' => $storedPath] = $this->createImportCsvUnderBase(
                'ivena-import-delete-'.bin2hex(random_bytes(8)).'.csv',
            );
        } else {
            $storedPath = $filePath;
            $csvPath = Path::isAbsolute($filePath)
                ? $filePath
                : Path::join((string) self::getContainer()->getParameter('kernel.project_dir'), $filePath);
        }

        $importProxy = ImportFactory::createOne([
            'name' => $namePrefix.' IT',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => $storedPath,
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => (int) filesize($csvPath),
            'rowCount' => 1,
            'rowsPassed' => 1,
            'rowsRejected' => null !== $rejectFilePath ? 1 : 0,
            'runCount' => 1,
            'runTime' => 50,
            'rejectFilePath' => $rejectFilePath,
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

        ['absolutePath' => $csvPath, 'storedPath' => $storedPath] = $this->createImportCsvUnderBase(
            'ivena-import-delete-'.bin2hex(random_bytes(8)).'.csv',
        );

        $importProxy = ImportFactory::createOne([
            'name' => 'DeleteKeepB IT',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => $storedPath,
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

    /**
     * @return array{absolutePath: string, storedPath: string}
     */
    private function createImportCsvUnderBase(string $basename): array
    {
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $importsBaseDir = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $targetDir = Path::join($importsBaseDir, date('Y/m'));
        new Filesystem()->mkdir($targetDir);

        $absolutePath = Path::join($targetDir, $basename);
        file_put_contents($absolutePath, "header1;header2\nvalue1;value2\n");

        $storedPath = ltrim(str_replace('\\', '/', Path::makeRelative($absolutePath, $projectDir)), '/');

        return ['absolutePath' => $absolutePath, 'storedPath' => $storedPath];
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
