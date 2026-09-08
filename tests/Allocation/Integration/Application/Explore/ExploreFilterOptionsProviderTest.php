<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Explore;

use App\Allocation\Application\Explore\ExploreFilterOptionsProvider;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
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
                'secondaryIndications',
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
        self::assertSame([], $options['secondaryIndications']);
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

    public function testSecondaryIndicationsListsOnlyUsedNormalizedIndicationsAndIsCached(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();

        $used = IndicationNormalizedFactory::createOne([
            'name' => 'Used Secondary',
            'code' => 8101,
        ]);
        $unused = IndicationNormalizedFactory::createOne([
            'name' => 'Unused Secondary',
            'code' => 8102,
        ]);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'secondaryIndicationNormalized' => $used,
        ]);

        $provider = self::getContainer()->get(ExploreFilterOptionsProvider::class);
        self::assertInstanceOf(ExploreFilterOptionsProvider::class, $provider);

        $first = $provider->secondaryIndications();
        self::assertSame([
            [
                'id' => (int) $used->getId(),
                'code' => 8101,
                'name' => 'Used Secondary',
            ],
        ], $first);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'secondaryIndicationNormalized' => $unused,
        ]);
        $second = $provider->secondaryIndications();
        self::assertSame($first, $second);

        $includingUnused = $provider->secondaryIndicationsIncluding((string) $unused->getId());
        self::assertSame((int) $unused->getId(), $includingUnused[1]['id'] ?? null);
        self::assertSame($first, $provider->secondaryIndicationsIncluding((string) $used->getId()));
        self::assertSame($first, $provider->secondaryIndicationsIncluding('none'));
    }
}
