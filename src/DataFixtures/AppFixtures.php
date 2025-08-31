<?php

namespace App\DataFixtures;

use App\Enum\ImportStatus;
use App\Factory\AllocationFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\ImportFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        UserFactory::createMany(5);

        StateFactory::createMany(3);
        DispatchAreaFactory::createMany(49);
        $dispatchArea = DispatchAreaFactory::createOne(
            ['name' => 'Test Area'])
        ;

        HospitalFactory::createMany(9);
        $hospital = HospitalFactory::createOne([
            'name' => 'Test Hospital',
            'dispatchArea' => $dispatchArea,
        ]);

        ImportFactory::createMany(14);
        ImportFactory::createOne([
            'name' => 'Test Import',
            'hospital' => $hospital,
            'status' => ImportStatus::PENDING,
        ]
        );

        AllocationFactory::createMany(100);

        $manager->flush();
    }

    /**
     * @return list<class-string<FixtureInterface>>
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
