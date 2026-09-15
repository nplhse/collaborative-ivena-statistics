<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\GeographicMap;

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
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GeographicMap\Infrastructure\Query\GeographicDestinationHospitalQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class GeographicDestinationHospitalQueryTest extends KernelTestCase
{
    use Factories;

    public function testImpossibleScopeReturnsEmptyList(): void
    {
        self::bootKernel();

        $query = self::getContainer()->get(GeographicDestinationHospitalQuery::class);
        $rows = $query->fetch(null, null, new StatisticsScopeCriteria([]));

        self::assertSame([], $rows);
    }

    public function testGroupsDestinationHospitalsAndKeepsRowsWithoutCoordinates(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'geo-dest-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'GeoDestState']);
        $area = DispatchAreaFactory::createOne(['name' => 'GeoDestArea', 'state' => $state]);
        $withCoords = HospitalFactory::createOne([
            'name' => 'GeoDestWithCoords',
            'state' => $state,
            'dispatchArea' => $area,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'latitude' => 50.11,
            'longitude' => 8.68,
        ]);
        $withoutCoords = HospitalFactory::createOne([
            'name' => 'GeoDestWithoutCoords',
            'state' => $state,
            'dispatchArea' => $area,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'latitude' => null,
            'longitude' => null,
        ]);

        SpecialityFactory::createOne(['name' => 'GeoDestSpec']);
        DepartmentFactory::createOne(['name' => 'GeoDestDept']);
        AssignmentFactory::createOne(['name' => 'GeoDestAssign']);
        IndicationRawFactory::createOne(['name' => 'GeoDestRaw', 'code' => 912_601]);

        $importA = ImportFactory::createOne(['name' => 'GeoDestImportA', 'hospital' => $withCoords, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'GeoDestImportB', 'hospital' => $withoutCoords, 'createdBy' => $user]);

        AllocationFactory::createMany(5, [
            'import' => $importA,
            'hospital' => $withCoords,
            'state' => $state,
            'dispatchArea' => $area,
            'createdAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
        ]);
        AllocationFactory::createMany(3, [
            'import' => $importB,
            'hospital' => $withoutCoords,
            'state' => $state,
            'dispatchArea' => $area,
            'createdAt' => new \DateTimeImmutable('2026-03-02 09:00:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        $rows = self::getContainer()->get(GeographicDestinationHospitalQuery::class)->fetch(
            null,
            null,
            new StatisticsScopeCriteria([$withCoords->getId(), $withoutCoords->getId()]),
        );

        self::assertCount(2, $rows);
        self::assertSame('GeoDestWithCoords', $rows[0]->name);
        self::assertSame(5, $rows[0]->caseCount);
        self::assertEqualsWithDelta(50.11, (float) $rows[0]->lat, 0.0001);
        self::assertEqualsWithDelta(8.68, (float) $rows[0]->lng, 0.0001);
        self::assertSame($area->getId(), $rows[0]->hospitalDispatchAreaId);
        self::assertSame('GeoDestWithoutCoords', $rows[1]->name);
        self::assertSame(3, $rows[1]->caseCount);
        self::assertNull($rows[1]->lat);
        self::assertNull($rows[1]->lng);
    }

    public function testDispatchAreaRelatedScopeIncludesInAreaAndOutflowHospitals(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'geo-dest-da-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'GeoDestDaState']);
        $areaA = DispatchAreaFactory::createOne(['name' => 'GeoDestDaAreaA', 'state' => $state]);
        $areaB = DispatchAreaFactory::createOne(['name' => 'GeoDestDaAreaB', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'GeoDestDaHospitalA',
            'state' => $state,
            'dispatchArea' => $areaA,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'latitude' => 51.31,
            'longitude' => 9.49,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'GeoDestDaHospitalB',
            'state' => $state,
            'dispatchArea' => $areaB,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'latitude' => 50.80,
            'longitude' => 8.77,
        ]);

        SpecialityFactory::createOne(['name' => 'GeoDestDaSpec']);
        DepartmentFactory::createOne(['name' => 'GeoDestDaDept']);
        AssignmentFactory::createOne(['name' => 'GeoDestDaAssign']);
        IndicationRawFactory::createOne(['name' => 'GeoDestDaRaw', 'code' => 912_602]);

        $importA = ImportFactory::createOne(['name' => 'GeoDestDaImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'GeoDestDaImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createMany(7, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $areaA,
            'createdAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
        ]);
        AllocationFactory::createMany(4, [
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $areaA,
            'createdAt' => new \DateTimeImmutable('2026-03-03 11:00:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());

        $rows = self::getContainer()->get(GeographicDestinationHospitalQuery::class)->fetch(
            null,
            null,
            new StatisticsScopeCriteria(hospitalIds: null, dispatchAreaId: $areaA->getId()),
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->name] = $row;
        }

        self::assertArrayHasKey('GeoDestDaHospitalA', $byName);
        self::assertArrayHasKey('GeoDestDaHospitalB', $byName);
        self::assertSame(7, $byName['GeoDestDaHospitalA']->caseCount);
        self::assertSame(4, $byName['GeoDestDaHospitalB']->caseCount);
        self::assertSame($areaA->getId(), $byName['GeoDestDaHospitalA']->hospitalDispatchAreaId);
        self::assertSame($areaB->getId(), $byName['GeoDestDaHospitalB']->hospitalDispatchAreaId);
    }
}
