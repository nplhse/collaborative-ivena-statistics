<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\DataFixtures\Content\ContentScenarioFixture;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class AreaReferenceFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly ReferenceDataLoader $loader,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $this->loader->loadAreas($this->adminUser());
        $manager->flush();
    }

    /**
     * @return list<class-string<Fixture>>
     */
    #[\Override]
    public function getDependencies(): array
    {
        return [ContentScenarioFixture::class];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getGroups(): array
    {
        return ['reference', 'geo', 'dev', 'statistics'];
    }

    private function adminUser(): User
    {
        return $this->getReference('admin', User::class);
    }
}
