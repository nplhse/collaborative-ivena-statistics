<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
use App\DataFixtures\Generator\Application\ImportBatchPlanner;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Generates synthetic allocations for the participating demo hospitals of the fixtures.
 *
 * Every active hospital of the preset gets one import per month from the HospitalMonthGenerator;
 * this class only adds the fixture concerns: preset, period, demo hospital and the allocation projection.
 * Closure analysis, closure volume projection and materialized views follow after the load
 * (`composer load-fixtures`), outside the fixture transaction.
 */
final readonly class SyntheticAllocationGenerator
{
    public function __construct(
        private FixtureHospitals $hospitals,
        private FixtureHospitalSelection $selection,
        private FixtureVolumeResolver $volumeResolver,
        private HospitalMonthGenerator $monthGenerator,
        private EntityManagerInterface $entityManager,
        private SyntheticDataPipeline $pipeline,
        private ClockInterface $clock,
    ) {
    }

    public function generate(): void
    {
        $volume = $this->volumeResolver->resolve();

        $lastDay = $volume->lastDay($this->clock);
        $periodStart = $lastDay->modify('first day of this month')->modify(sprintf('-%d months', $volume->months));
        $hospitals = $this->selection->active($volume, $this->hospitals->participatingHospitals());
        $fooHospital = $this->requireDemoHospitalOwnedBy('foo');
        if (!\in_array((int) $fooHospital->getId(), array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $hospitals), true)) {
            throw new \RuntimeException(sprintf('The demo hospital of "foo" is not among the active hospitals of profile "%s".', $volume->profile));
        }

        usort($hospitals, static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()));
        $hospitalIds = array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $hospitals);
        foreach ($hospitalIds as $hospitalId) {
            foreach (ImportBatchPlanner::months($periodStart, $lastDay) as $month) {
                $hospital = $this->entityManager->find(Hospital::class, $hospitalId);
                if (!$hospital instanceof Hospital) {
                    throw new \RuntimeException(sprintf('Hospital %d disappeared during generation.', $hospitalId));
                }
                $this->monthGenerator->generate($volume, $hospital, $month, $lastDay);
                $this->entityManager->clear();
            }
        }

        if ($volume->rebuildProjection) {
            $this->pipeline->rebuildAllocationProjection();
        }
    }

    private function requireDemoHospitalOwnedBy(string $username): Hospital
    {
        $hospital = $this->hospitals->findHospitalOwnedByUsername($username);
        if (!$hospital instanceof Hospital) {
            throw new \RuntimeException(sprintf('Demo user "%s" must own a participating hospital.', $username));
        }
        if (!$hospital->isParticipating()) {
            throw new \RuntimeException(sprintf('Demo hospital for user "%s" must be participating.', $username));
        }

        return $hospital;
    }
}
