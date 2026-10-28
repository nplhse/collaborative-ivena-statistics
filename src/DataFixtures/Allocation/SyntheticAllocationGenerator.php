<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
use App\DataFixtures\Generator\Application\ImportBatchPlanner;
use App\DataFixtures\Generator\Application\Scenario\PresetScenarioPlanner;
use App\DataFixtures\Generator\Application\Scenario\ScenarioGenerator;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Generates synthetic allocations for the participating demo hospitals of the fixtures.
 *
 * Every active hospital of the preset gets one import per month from the HospitalMonthGenerator; then
 * the scenarios of the preset are laid over the data (PresetScenarioPlanner). This class only adds the
 * fixture concerns: preset, period, demo hospital, scenarios, the allocation projection and the KPIs.
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
        private PresetScenarioPlanner $scenarioPlanner,
        private ScenarioGenerator $scenarioGenerator,
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

        $this->applyScenarios($volume, $hospitalIds, $periodStart, $lastDay);

        if ($volume->rebuildProjection) {
            $this->pipeline->rebuildAllocationProjection();
            $this->pipeline->aggregateAllKpis();
        }
    }

    /**
     * Projections and KPIs follow with the rest of the load, so the scenarios skip their own rebuild.
     *
     * @param list<int> $hospitalIds
     */
    private function applyScenarios(FixtureVolume $volume, array $hospitalIds, \DateTimeImmutable $periodStart, \DateTimeImmutable $lastDay): void
    {
        foreach ($this->scenarioPlanner->plan($volume, $this->find($hospitalIds), $periodStart, $lastDay) as $scenario) {
            $this->scenarioGenerator->apply($scenario->definition, $volume, $this->find($scenario->hospitalIds), $scenario->startDay, $lastDay, $scenario->area, rebuild: false);
            $this->entityManager->clear();
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Hospital>
     */
    private function find(array $ids): array
    {
        return array_map(function (int $id): Hospital {
            $hospital = $this->entityManager->find(Hospital::class, $id);
            if (!$hospital instanceof Hospital) {
                throw new \RuntimeException(sprintf('Hospital %d disappeared during generation.', $id));
            }

            return $hospital;
        }, $ids);
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
