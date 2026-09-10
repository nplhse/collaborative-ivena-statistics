<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query\IsochroneOriginMap;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IsochroneOriginMap\IsochroneOriginBandQueryInterface;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IsochroneOriginBandQueryTest extends KernelTestCase
{
    use Factories;

    public function testGroupsByTenMinuteBandsAndRespectsIndicationFilter(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'iso-band-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'IsoBandState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IsoBandDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IsoBandHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'IsoBandSpec']);
        DepartmentFactory::createOne(['name' => 'IsoBandDept']);
        AssignmentFactory::createOne(['name' => 'IsoBandAssign']);
        IndicationRawFactory::createOne(['name' => 'IsoBandRaw', 'code' => 912_360]);
        $targetIndication = IndicationNormalizedFactory::createOne(['name' => 'IsoBand Target']);
        $otherIndication = IndicationNormalizedFactory::createOne(['name' => 'IsoBand Other']);

        $import = ImportFactory::createOne(['name' => 'IsoBandImport', 'hospital' => $hospital, 'createdBy' => $user]);

        $base = [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 40,
        ];

        $created = new \DateTimeImmutable('2026-04-01 08:00:00');
        AllocationFactory::createOne($base + [
            'indicationNormalized' => $targetIndication,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+4 minutes'),
        ]);
        AllocationFactory::createOne($base + [
            'indicationNormalized' => $targetIndication,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+15 minutes'),
        ]);
        AllocationFactory::createOne($base + [
            'indicationNormalized' => $targetIndication,
            'createdAt' => $created,
            'arrivalAt' => $created,
        ]);
        AllocationFactory::createOne($base + [
            'indicationNormalized' => $targetIndication,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+55 minutes'),
        ]);
        AllocationFactory::createOne($base + [
            'indicationNormalized' => $otherIndication,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+8 minutes'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $query = self::getContainer()->get(IsochroneOriginBandQueryInterface::class);
        $scope = new StatisticsScopeCriteria([$hospital->getId()]);
        $from = new \DateTimeImmutable('2026-04-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2026-05-01 00:00:00');

        $all = $query->fetch($from, $toExclusive, $scope);
        self::assertSame(3, $all->countFor('10'));
        self::assertSame(1, $all->countFor('20'));
        self::assertSame(0, $all->countFor('unknown'));
        self::assertSame(1, $all->countFor('beyond_max'));
        self::assertSame(5, $all->total());

        $filtered = $query->fetch($from, $toExclusive, $scope, [$targetIndication->getId()]);
        self::assertSame(2, $filtered->countFor('10'));
        self::assertSame(1, $filtered->countFor('20'));
        self::assertSame(0, $filtered->countFor('unknown'));
        self::assertSame(1, $filtered->countFor('beyond_max'));
        self::assertSame(4, $filtered->total());

        $empty = $query->fetch($from, $toExclusive, $scope, []);
        self::assertSame(0, $empty->total());

        $noHospitals = $query->fetch($from, $toExclusive, new StatisticsScopeCriteria([]));
        self::assertSame(0, $noHospitals->total());
    }
}
