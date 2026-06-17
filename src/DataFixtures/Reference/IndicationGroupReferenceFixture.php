<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class IndicationGroupReferenceFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const int DEV_GROUP_COUNT = 5;

    public function __construct(
        private readonly IndicationGroupReferenceLoader $loader,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $this->loader->loadGroups(
            $this->adminUser(),
            $this->loader->pickDeterministicSubset(self::DEV_GROUP_COUNT),
        );
        $manager->flush();
    }

    /**
     * @return list<class-string<Fixture>>
     */
    #[\Override]
    public function getDependencies(): array
    {
        return [IndicationReferenceFixture::class];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getGroups(): array
    {
        return ['dev', 'indication_groups'];
    }

    private function adminUser(): User
    {
        return $this->getReference('admin', User::class);
    }
}
