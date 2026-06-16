<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class HospitalReferenceFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly ReferenceDataLoader $loader,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $this->loader->loadHospitals($this->adminUser(), all: true);
        $manager->flush();
    }

    /**
     * @return list<class-string<Fixture>>
     */
    #[\Override]
    public function getDependencies(): array
    {
        return [AreaReferenceFixture::class];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getGroups(): array
    {
        return ['reference', 'hospitals', 'dev', 'statistics'];
    }

    private function adminUser(): User
    {
        return $this->getReference('admin', User::class);
    }
}
