<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Indication;

use App\Allocation\Application\Indication\BackfillAllocationIndicationNormalizedService;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillAllocationIndicationNormalizedServiceTest extends KernelTestCase
{
    use Factories;

    public function testBackfillCopiesTargetOntoRawNormalizedAndAllocation(): void
    {
        self::bootKernel();

        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Backfill Norm', 'code' => 99001]);
        $raw = IndicationRawFactory::createOne([
            'code' => 99001,
            'name' => 'Backfill Raw',
            'target' => $normalized,
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE indication_raw SET normalized_id = NULL WHERE id = :id',
            ['id' => $raw->getId()],
        );

        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdBy' => $user,
        ]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);

        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();

        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdAt' => new \DateTimeImmutable('2025-03-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-03-01 08:30:00'),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'infection' => null,
            'secondaryTransport' => null,
            'indicationRaw' => $raw,
            'indicationNormalized' => null,
        ]);

        /** @var BackfillAllocationIndicationNormalizedService $service */
        $service = self::getContainer()->get(BackfillAllocationIndicationNormalizedService::class);
        $result = $service->run(dryRun: false);

        self::assertGreaterThanOrEqual(1, $result->rawNormalizedSyncedFromTarget);
        self::assertGreaterThanOrEqual(1, $result->allocationsPrimaryUpdated);

        $rawNormalizedId = $connection->fetchOne(
            'SELECT normalized_id FROM indication_raw WHERE id = :id',
            ['id' => $raw->getId()],
        );
        self::assertSame($normalized->getId(), (int) $rawNormalizedId);

        $allocationNormalizedId = $connection->fetchOne(
            'SELECT indication_normalized_id FROM allocation WHERE id = :id',
            ['id' => $allocation->getId()],
        );
        self::assertSame($normalized->getId(), (int) $allocationNormalizedId);
    }

    public function testDryRunDoesNotPersistChanges(): void
    {
        self::bootKernel();

        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Dry Run Norm', 'code' => 99002]);
        $raw = IndicationRawFactory::createOne([
            'code' => 99002,
            'name' => 'Dry Run Raw',
            'target' => $normalized,
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE indication_raw SET normalized_id = NULL WHERE id = :id',
            ['id' => $raw->getId()],
        );

        /** @var BackfillAllocationIndicationNormalizedService $service */
        $service = self::getContainer()->get(BackfillAllocationIndicationNormalizedService::class);
        $service->run(dryRun: true);

        self::assertGreaterThanOrEqual(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM indication_raw WHERE normalized_id IS NULL AND target_id IS NOT NULL',
            ),
        );
    }
}
