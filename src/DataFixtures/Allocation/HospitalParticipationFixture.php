<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\DataFixtures\Reference\IndicationReferenceFixture;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class HospitalParticipationFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly ParticipatingHospitalProvisioner $provisioner,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $foo = $this->getReference('foo', User::class);

        $this->provisioner->provision($foo);
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
        return ['participation', 'dev', 'allocations', 'statistics'];
    }
}
