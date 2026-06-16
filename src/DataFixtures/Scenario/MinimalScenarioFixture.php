<?php

declare(strict_types=1);

namespace App\DataFixtures\Scenario;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\DataFixtures\UserFixtures;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class MinimalScenarioFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Test Hospital',
            'dispatchArea' => $area,
            'state' => $state,
        ]);

        ImportFactory::createOne([
            'name' => 'Test Import',
            'hospital' => $hospital,
            'status' => ImportStatus::PENDING,
        ]);

        $manager->flush();
    }

    /**
     * @return list<class-string<Fixture>>
     */
    #[\Override]
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getGroups(): array
    {
        return ['minimal'];
    }
}
