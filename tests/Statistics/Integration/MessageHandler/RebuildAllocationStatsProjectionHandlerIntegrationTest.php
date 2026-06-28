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
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use App\Statistics\Application\MessageHandler\RebuildAllocationStatsProjectionHandler;
use App\Statistics\Infrastructure\Entity\ProjectionStateHospitalCount;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
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
        $stateId = self::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            'SELECT state_id FROM allocation WHERE import_id = :importId LIMIT 1',
            ['importId' => $importId],
        );

        self::assertNotFalse($stateId);

        return (int) $stateId;
    }
}
