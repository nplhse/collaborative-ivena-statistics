<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Application;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Application\PublicId\PublicIdBackfillService;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;

/**
 * The backfill writes the new public IDs with SQL; since `public_id` is stored as uuid (#1398) the value has to be
 * cast. Runs against the migrated schema.
 */
final class PublicIdBackfillServiceTest extends DatabaseKernelTestCase
{
    public function testBackfillAssignsAUuidToAnAllocationWithoutPublicId(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $service = self::getContainer()->get(PublicIdBackfillService::class);
        UserFactory::createOne();
        DispatchAreaFactory::createOne(['state' => StateFactory::createOne()]);
        HospitalFactory::createOne();
        ImportFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
        $allocationId = AllocationFactory::createOne()->getId();
        $connection->executeStatement('UPDATE allocation SET public_id = NULL WHERE id = :id', ['id' => $allocationId]);

        $result = $service->run(tables: ['allocation']);

        self::assertSame(1, $result->updatedByTable['allocation'] ?? null);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $connection->fetchOne('SELECT public_id FROM allocation WHERE id = :id', ['id' => $allocationId]),
        );
    }
}
