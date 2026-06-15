<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\HospitalPopulation;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\HospitalPopulation\Infrastructure\Query\GetHospitalPopulationQuery;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GetHospitalPopulationQueryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testReturnsHospitalsWithStateAndDispatchArea(): void
    {
        self::bootKernel();

        UserFactory::createOne(['username' => 'hp-query-'.bin2hex(random_bytes(4))]);
        $stateA = StateFactory::createOne(['name' => 'Example State A']);
        $stateB = StateFactory::createOne(['name' => 'Example State B']);
        $areaA = DispatchAreaFactory::createOne(['name' => 'Area Alpha', 'state' => $stateA]);
        $areaB = DispatchAreaFactory::createOne(['name' => 'Area Beta', 'state' => $stateB]);

        HospitalFactory::createOne([
            'name' => 'Hospital Alpha',
            'state' => $stateA,
            'dispatchArea' => $areaA,
            'size' => HospitalSize::LARGE,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'beds' => 400,
            'latitude' => 50.11,
            'longitude' => 8.68,
            'isParticipating' => true,
        ]);
        HospitalFactory::createOne([
            'name' => 'Hospital Beta',
            'state' => $stateB,
            'dispatchArea' => $areaB,
            'size' => HospitalSize::SMALL,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'beds' => 120,
            'latitude' => 51.31,
            'longitude' => 9.48,
            'isParticipating' => false,
        ]);

        $snapshots = self::getContainer()->get(GetHospitalPopulationQuery::class)();

        self::assertCount(2, $snapshots);
        self::assertSame('Example State A', $snapshots[0]->stateName);
        self::assertSame('Area Alpha', $snapshots[0]->dispatchAreaName);
        self::assertSame('Hospital Alpha', $snapshots[0]->name);
        self::assertSame(400, $snapshots[0]->beds);
        self::assertTrue($snapshots[0]->isParticipating);
    }
}
