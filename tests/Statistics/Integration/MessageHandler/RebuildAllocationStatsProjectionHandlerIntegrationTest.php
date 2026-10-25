<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\MessageHandler;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationProjectionRunTelemetryInterface;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Statistics\Application\Contract\MaterializedViewRefresherInterface;
use App\Statistics\Application\Contract\ProjectionOverviewChangeDetectorInterface;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Application\MessageHandler\RebuildAllocationStatsProjectionHandler;
use App\Statistics\Infrastructure\Entity\ProjectionStateHospitalCount;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class RebuildAllocationStatsProjectionHandlerIntegrationTest extends KernelTestCase
{
    use Factories;

    private RebuildAllocationStatsProjectionHandler $projectionHandler;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->projectionHandler = $container->get(RebuildAllocationStatsProjectionHandler::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $container->get(OverviewMaterializedViewsInstaller::class)->ensureInstalled();
    }

    public function testFirstImportRefreshesMaterializedViewsWithoutManualRefresh(): void
    {
        $importId = $this->seedImport('HandlerFirstImport');
        $stateId = $this->stateIdForImport($importId);

        ($this->projectionHandler)(new RebuildAllocationStatsProjection($importId));

        $row = $this->entityManager->find(ProjectionStateHospitalCount::class, $stateId);
        self::assertInstanceOf(ProjectionStateHospitalCount::class, $row);
        self::assertSame(1, $row->getHospitalCount());
    }

    public function testReimportKeepsMaterializedViewCountsStable(): void
    {
        $importId = $this->seedImport('HandlerReimport');
        $stateId = $this->stateIdForImport($importId);

        ($this->projectionHandler)(new RebuildAllocationStatsProjection($importId));
        ($this->projectionHandler)(new RebuildAllocationStatsProjection($importId));

        $row = $this->entityManager->find(ProjectionStateHospitalCount::class, $stateId);
        self::assertInstanceOf(ProjectionStateHospitalCount::class, $row);
        self::assertSame(1, $row->getHospitalCount());
    }

    public function testEachRebuildIsRecordedInJobRunWithItsRows(): void
    {
        $importId = $this->seedImport('HandlerJobRun');

        ($this->projectionHandler)(new RebuildAllocationStatsProjection($importId));
        ($this->projectionHandler)(new RebuildAllocationStatsProjection($importId));

        $connection = self::getContainer()->get(Connection::class);
        $runs = $connection->fetchAllAssociative(
            "SELECT * FROM job_run WHERE job = 'allocation_projection_rebuild' AND import_id = ? ORDER BY started_at, id",
            [$importId],
        );
        self::assertCount(2, $runs);
        $written = (int) $connection->fetchOne('SELECT COUNT(*) FROM allocation_stats_projection WHERE import_id = ?', [$importId]);
        self::assertGreaterThan(0, $written);

        $first = json_decode((string) $runs[0]['metrics'], true);
        $second = json_decode((string) $runs[1]['metrics'], true);
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('succeeded', $runs[0]['status']);
        self::assertSame('messenger', $runs[0]['triggered_by']);
        self::assertSame($this->hospitalIdForImport($importId), (int) $runs[0]['hospital_id']);
        self::assertSame(0, $first['rows_deleted']);
        self::assertSame($written, $first['rows_written']);
        self::assertTrue($first['mv_refreshed'], 'The first import brings a new hospital.');
        foreach (['change_detection_ms', 'delete_ms', 'insert_ms', 'closure_request_ms', 'mv_refresh_ms'] as $step) {
            self::assertIsInt($first[$step], $step);
        }
        self::assertSame($written, $second['rows_deleted'], 'A rebuild replaces the rows of the previous one.');
        self::assertSame($written, $second['rows_written']);
        self::assertFalse($second['mv_refreshed']);
        self::assertArrayNotHasKey('mv_refresh_ms', $second);
    }

    public function testAFailedRebuildIsRecordedAndRethrown(): void
    {
        $importId = $this->seedImport('HandlerJobRunFailure');
        $container = self::getContainer();
        $rebuilder = $this->createStub(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->method('rebuildForImport')->willThrowException(new \RuntimeException('SQLSTATE: HandlerJobRunFailureHospital'));
        $handler = new RebuildAllocationStatsProjectionHandler(
            $rebuilder,
            $container->get(ProjectionOverviewChangeDetectorInterface::class),
            $container->get(MaterializedViewRefresherInterface::class),
            $container->get(ClosureRebuildRequestInterface::class),
            $container->get(Connection::class),
            $container->get(LoggerInterface::class),
            $container->get(AllocationProjectionRunTelemetryInterface::class),
        );

        try {
            $handler(new RebuildAllocationStatsProjection($importId));
            self::fail('The rebuild error must reach Messenger.');
        } catch (\RuntimeException $e) {
            self::assertSame('SQLSTATE: HandlerJobRunFailureHospital', $e->getMessage());
        }

        $run = $container->get(Connection::class)->fetchAssociative(
            "SELECT status, error_class, error_message, metrics FROM job_run WHERE job = 'allocation_projection_rebuild' AND import_id = ?",
            [$importId],
        );
        self::assertIsArray($run);
        self::assertSame('failed', $run['status']);
        self::assertSame(\RuntimeException::class, $run['error_class']);
        self::assertSame('SQLSTATE: HandlerJobRunFailureHospital', $run['error_message'], 'The message stays local in job_run.');
        $metrics = json_decode((string) $run['metrics'], true);
        self::assertIsArray($metrics);
        self::assertArrayNotHasKey('rows_written', $metrics);
    }

    private function hospitalIdForImport(int $importId): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT hospital_id FROM import WHERE id = ?', [$importId]);
    }

    private function seedImport(string $prefix): int
    {
        $user = UserFactory::createOne(['username' => strtolower($prefix).'-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => $prefix.'State']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => $prefix.'Dispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => $prefix.'Hospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
        ]);
        $import = ImportFactory::createOne([
            'name' => $prefix.'Import',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        SpecialityFactory::createOne(['name' => $prefix.'Speciality']);
        DepartmentFactory::createOne(['name' => $prefix.'Department']);
        AssignmentFactory::createOne(['name' => $prefix.'Assignment']);
        IndicationRawFactory::createOne(['name' => $prefix.'Indication', 'code' => random_int(900_000, 999_999)]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 10:20:00'),
        ]);

        return (int) $import->getId();
    }

    private function stateIdForImport(int $importId): int
    {
        $stateId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT state_id FROM allocation WHERE import_id = :importId LIMIT 1',
            ['importId' => $importId],
        );

        self::assertNotFalse($stateId);

        return (int) $stateId;
    }
}
