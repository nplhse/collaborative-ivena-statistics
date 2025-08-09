<?php

namespace App\DataFixtures;

use App\Factory\DispatchAreaFactory;
use App\Factory\StateFactory;
use App\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        UserFactory::createMany(5);

        StateFactory::createMany(3);
        DispatchAreaFactory::createMany(50);

        $manager->flush();
    }
}
