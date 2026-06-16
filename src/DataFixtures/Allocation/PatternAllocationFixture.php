<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

final class PatternAllocationFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly SyntheticAllocationGenerator $generator,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $this->generator->generate();
    }

    /**
     * @return list<class-string<Fixture>>
     */
    #[\Override]
    public function getDependencies(): array
    {
        return [HospitalParticipationFixture::class];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getGroups(): array
    {
        return ['allocations', 'dev', 'statistics'];
    }
}
