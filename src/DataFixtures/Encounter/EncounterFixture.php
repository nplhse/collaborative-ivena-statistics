<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Allocation\FixtureHospitals;
use App\DataFixtures\Allocation\PatternAllocationFixture;
use App\DataFixtures\Encounter\Infrastructure\SyntheticEncounterLoader;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\ImportBatchPlanner;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;

/**
 * Synthetic encounter data for the demo hospital of `foo` (#1052): the visits of its synthetic
 * allocations of the last three months of the period (the current month included), one encounter
 * import per month. Other hospitals get no encounter data; the pilot runs with one hospital.
 *
 * `foo` owns that hospital and gets the pilot role `ROLE_ENCOUNTER_BETA` and an encounter grant with
 * upload, review and case access for it, so the demo user sees the visits (ADR 027). Fixtures only.
 */
final class EncounterFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public const int MONTHS = 3;

    public function __construct(
        private readonly FixtureHospitals $hospitals,
        private readonly FixtureVolumeResolver $volumeResolver,
        private readonly SyntheticEncounterLoader $loader,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $hospital = $this->hospitals->findHospitalOwnedByUsername('foo');
        $owner = $hospital?->getOwner();
        if (!$hospital instanceof Hospital || !$owner instanceof User) {
            throw new \RuntimeException('Demo user "foo" must own a participating hospital.');
        }

        $this->grantEncounterAccess($manager, $hospital, $owner);

        $volume = $this->volumeResolver->resolve();
        $lastDay = $volume->lastDay($this->clock);
        $months = ImportBatchPlanner::months($lastDay->modify('first day of this month')->modify(sprintf('-%d months', min(self::MONTHS, $volume->months + 1) - 1)), $lastDay);
        foreach ($months as $month) {
            $monthEnd = new \DateTimeImmutable($month.'-01')->modify('last day of this month');
            $uploadedAt = min($monthEnd, $lastDay)->modify('+1 day')->setTime(10, 0);
            $this->loader->load($hospital, $volume->seed, $month, $lastDay, $owner, $uploadedAt);
        }
    }

    /**
     * The owner already has hospital access, which an encounter grant needs (ADR 027).
     */
    private function grantEncounterAccess(ObjectManager $manager, Hospital $hospital, User $owner): void
    {
        $owner->setRoles(array_values(array_unique([...$owner->getRoles(), UserRole::ENCOUNTER_BETA])));
        $manager->persist(new EncounterAccessGrant($hospital, $owner, [EncounterGrantAction::Upload, EncounterGrantAction::Review, EncounterGrantAction::ViewCases]));
        $manager->flush();
    }

    /**
     * @return list<class-string<Fixture>>
     */
    #[\Override]
    public function getDependencies(): array
    {
        return [PatternAllocationFixture::class];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getGroups(): array
    {
        return ['encounters', 'dev'];
    }
}
