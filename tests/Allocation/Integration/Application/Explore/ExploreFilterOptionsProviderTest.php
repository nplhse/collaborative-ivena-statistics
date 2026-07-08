<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Explore;

use App\Allocation\Application\Explore\ExploreFilterOptionsProvider;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExploreFilterOptionsProviderTest extends KernelTestCase
{
    use Factories;

    public function testSecondCallUsesCachedStates(): void
    {
        self::bootKernel();
        StateFactory::createOne(['name' => 'Hessen']);

        $provider = self::getContainer()->get(ExploreFilterOptionsProvider::class);
        self::assertInstanceOf(ExploreFilterOptionsProvider::class, $provider);

        $first = $provider->states();
        StateFactory::createOne(['name' => 'Bayern']);
        $second = $provider->states();

        self::assertSame([['id' => $first[0]['id'], 'name' => 'Hessen']], $first);
        self::assertSame($first, $second);
    }

    public function testAllocationListOptionsReturnsMappedReferenceArrays(): void
    {
        self::bootKernel();

        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'North']);
        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'STEMI',
            'code' => 42,
        ]);
        $assignment = AssignmentFactory::createOne(['name' => 'Zebra Assignment']);
        AssignmentFactory::createOne(['name' => 'Alpha Assignment']);
        DepartmentFactory::createOne(['name' => 'Cardiology']);
        SpecialityFactory::createOne(['name' => 'Internal Medicine']);
        OccasionFactory::createOne(['name' => 'Emergency']);
        SecondaryTransportFactory::createOne(['name' => 'Capacity']);
        InfectionFactory::createOne(['name' => 'MRSA']);

        $provider = self::getContainer()->get(ExploreFilterOptionsProvider::class);
        self::assertInstanceOf(ExploreFilterOptionsProvider::class, $provider);

        $options = $provider->allocationListOptions();

        self::assertSame(
            [
                'states',
                'dispatchAreas',
                'indications',
                'secondaryTransports',
                'infections',
                'departments',
                'specialities',
                'assignments',
                'occasions',
            ],
            array_keys($options),
        );
        self::assertSame([
            'id' => (int) $state->getId(),
            'name' => 'Hessen',
        ], $options['states'][0]);
        self::assertSame([
            'id' => (int) $dispatchArea->getId(),
            'name' => 'North',
        ], $options['dispatchAreas'][0]);
        self::assertSame([
            'id' => (int) $indication->getId(),
            'code' => 42,
            'name' => 'STEMI',
        ], $options['indications'][0]);
        self::assertSame(['Alpha Assignment', 'Zebra Assignment'], array_column($options['assignments'], 'name'));
    }

    public function testSecondCallUsesCachedIndicationGroups(): void
    {
        self::bootKernel();
        IndicationGroupFactory::createOne(['name' => 'Cardiac group']);

        $provider = self::getContainer()->get(ExploreFilterOptionsProvider::class);
        self::assertInstanceOf(ExploreFilterOptionsProvider::class, $provider);

        $first = $provider->indicationGroups();
        IndicationGroupFactory::createOne(['name' => 'Neurology group']);
        $second = $provider->indicationGroups();

        self::assertSame([['id' => $first[0]['id'], 'name' => 'Cardiac group']], $first);
        self::assertSame($first, $second);
    }
}
