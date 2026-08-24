<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Application\Dashboard;

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
use App\Content\Application\Dashboard\DashboardMetricsService;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardMetricsServiceTest extends DatabaseKernelTestCase
{
    use RefreshesStatisticsFunctionalDataTrait;

    public function testComputesTotalsAndLast30DaysDeltas(): void
    {
        $old = new \DateTimeImmutable('-40 days');
        $recent = new \DateTimeImmutable('-2 days');
        $userOld = UserFactory::createOne([
            'username' => 'metrics-old',
            'createdAt' => $old,
        ]);
        UserFactory::createOne([
            'username' => 'metrics-new',
            'createdAt' => $recent,
        ]);

        $state = StateFactory::createOne(['name' => 'Metrics State', 'createdBy' => $userOld]);
        $dispatchArea = DispatchAreaFactory::createOne([
            'name' => 'Metrics Dispatch',
            'createdBy' => $userOld,
            'state' => $state,
        ]);
        $hospitalOld = HospitalFactory::createOne([
            'name' => 'Metrics Hospital Old',
            'createdBy' => $userOld,
            'owner' => $userOld,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => true,
            'createdAt' => $old,
        ]);
        $hospitalNew = HospitalFactory::createOne([
            'name' => 'Metrics Hospital New',
            'createdBy' => $userOld,
            'owner' => $userOld,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => true,
            'createdAt' => $recent,
        ]);
        HospitalFactory::createOne([
            'name' => 'Metrics Hospital Inactive',
            'createdBy' => $userOld,
            'owner' => $userOld,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'isParticipating' => false,
            'createdAt' => $recent,
        ]);

        SpecialityFactory::createOne(['name' => 'Metrics Spec', 'createdBy' => $userOld]);
        DepartmentFactory::createOne(['name' => 'Metrics Dept', 'createdBy' => $userOld]);
        AssignmentFactory::createOne(['name' => 'Metrics Assign', 'createdBy' => $userOld]);
        OccasionFactory::createOne(['name' => 'Metrics Occ', 'createdBy' => $userOld]);
        IndicationRawFactory::createOne(['name' => 'Metrics Raw', 'createdBy' => $userOld]);
        IndicationNormalizedFactory::createOne(['name' => 'Metrics Norm', 'createdBy' => $userOld]);

        $importOld = ImportFactory::createOne([
            'name' => 'Metrics Import Old',
            'createdBy' => $userOld,
            'hospital' => $hospitalOld,
            'createdAt' => $old,
        ]);
        $importNew = ImportFactory::createOne([
            'name' => 'Metrics Import New',
            'createdBy' => $userOld,
            'hospital' => $hospitalNew,
            'createdAt' => $recent,
        ]);

        AllocationFactory::createMany(3, [
            'createdAt' => $old,
            'import' => $importOld,
            'hospital' => $hospitalOld,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        AllocationFactory::createMany(2, [
            'createdAt' => $recent,
            'import' => $importNew,
            'hospital' => $hospitalNew,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        $this->rebuildProjectionForImports([(int) $importOld->getId(), (int) $importNew->getId()]);
        self::getContainer()->get(CacheInterface::class)->delete('dashboard.allocation_counts');

        $metrics = self::getContainer()->get(DashboardMetricsService::class)->get();
        $byKey = [];
        foreach ($metrics->items as $item) {
            $byKey[$item->key] = $item;
        }

        self::assertSame(5, $byKey['allocations']->value);
        self::assertSame(2, $byKey['allocations']->deltaLast30Days);
        self::assertSame(2, $byKey['hospitals']->value);
        self::assertSame(1, $byKey['hospitals']->deltaLast30Days);
        self::assertSame(2, $byKey['users']->value);
        self::assertSame(1, $byKey['users']->deltaLast30Days);
        self::assertSame(2, $byKey['imports']->value);
        self::assertSame(1, $byKey['imports']->deltaLast30Days);

        $cached = self::getContainer()->get(DashboardMetricsService::class)->get();
        self::assertSame(5, $cached->value('allocations'));
        self::assertSame(2, $cached->value('imports'));
    }

    public function testRejectsUnexpectedCachedAllocationPayload(): void
    {
        $cache = self::getContainer()->get(CacheInterface::class);
        $cache->delete('dashboard.allocation_counts');
        $cache->get('dashboard.allocation_counts', static function (ItemInterface $item): string {
            $item->expiresAfter(3600);

            return 'invalid';
        });

        try {
            self::getContainer()->get(DashboardMetricsService::class)->get();
            self::fail('Expected a LogicException for an unexpected cache payload.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('unexpected type', $exception->getMessage());
        } finally {
            $cache->delete('dashboard.allocation_counts');
        }
    }
}
