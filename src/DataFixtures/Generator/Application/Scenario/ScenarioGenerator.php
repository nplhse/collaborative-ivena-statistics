<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Scenario;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\MciCase;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Application\ClosedDepartments;
use App\DataFixtures\Generator\Application\HospitalProfile;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\DataFixtures\Generator\Application\PlannedClosure;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
use App\DataFixtures\Generator\Application\SyntheticImportMarker;
use App\DataFixtures\Generator\Application\UploadTime;
use App\DataFixtures\Generator\Infrastructure\Dbal\AllocationRowWriter;
use App\DataFixtures\Generator\Infrastructure\Dbal\ClosureIntervalWriter;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Application\PatternLookupResolver;
use App\DataFixtures\Pattern\Application\PatternSampler;
use App\DataFixtures\Pattern\Application\PatternSetLoader;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * Lays a scenario over existing data (ADR 022).
 *
 * An overlay only adds rows: per affected hospital its own scenario import with extra allocations or
 * MCI cases, and a closure import when the scenario closes departments. Base data stays as it is.
 * The scenario draws from its own streams, so the same scenario, start day and seed give the same
 * rows; applying it twice to a hospital and start day is refused. The intensity scales extra cases,
 * MCI patients and closures. Removing a scenario deletes its imports with the regular import deletion.
 *
 * MCI patients are spread over the hospitals by tier and size: full care and large hospitals take more,
 * every hospital at least one.
 */
final readonly class ScenarioGenerator
{
    public const float MIN_INTENSITY = 0.5;
    public const float MAX_INTENSITY = 2.0;

    /** Weight of a hospital in an MCI, by tier and size. */
    private const array MCI_TIER_WEIGHT = ['Full' => 3.0, 'Extended' => 2.0, 'Basic' => 1.0];
    private const array MCI_SIZE_WEIGHT = ['Large' => 1.5, 'Medium' => 1.0, 'Small' => 0.6];

    public function __construct(
        private HospitalProfileResolver $profiles,
        private PatternSetLoader $patterns,
        private PatternSampler $sampler,
        private PatternLookupResolver $lookups,
        private ClosureIntervalWriter $closureWriter,
        private SyntheticDataPipeline $pipeline,
        private RandomStreams $streams,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ImportDeletionService $importDeletion,
    ) {
    }

    /**
     * What the scenario would add per hospital, without writing anything.
     *
     * @param list<Hospital> $hospitals
     * @param float          $intensity scales extra cases, MCI patients and closures (MIN_INTENSITY–MAX_INTENSITY)
     *
     * @return list<ScenarioHospitalPlan>
     *
     * @throws \DomainException when the scenario cannot be applied
     */
    public function plan(ScenarioDefinition $scenario, FixtureVolume $volume, array $hospitals, \DateTimeImmutable $startDay, \DateTimeImmutable $lastDay, string $area, float $intensity = 1.0): array
    {
        if ($intensity < self::MIN_INTENSITY || $intensity > self::MAX_INTENSITY) {
            throw new \DomainException(sprintf('The intensity must lie between %.1f and %.1f, got %s.', self::MIN_INTENSITY, self::MAX_INTENSITY, $intensity));
        }
        if ([] === $hospitals) {
            throw new \DomainException('The scenario needs at least one participating hospital.');
        }
        $start = $startDay->setTime(0, 0);
        if ($start > $lastDay) {
            throw new \DomainException(sprintf('%s lies after the last day of the period (%s).', $start->format('Y-m-d'), $lastDay->format('Y-m-d')));
        }
        foreach ($hospitals as $hospital) {
            if ($this->isApplied($scenario, $hospital, $start)) {
                throw new \DomainException(sprintf('%s already has the scenario "%s" starting on %s.', (string) $hospital->getName(), $scenario->name, $start->format('Y-m-d')));
            }
        }

        $areaRandom = $this->streams->stream($volume->seed, $area, $start->format('Y-m-d'), 'scenario:'.$scenario->name);
        $days = $this->days($scenario, $start, $lastDay, $areaRandom);
        $mciShares = $scenario->isMci()
            ? $this->spread(max(1, (int) round((float) $areaRandom->getInt(...$scenario->mciPatients) * $intensity)), $hospitals, $areaRandom)
            : [];
        $mciStart = $start->setTime($areaRandom->getInt(6, 20), $areaRandom->getInt(0, 59));
        $mciWindow = $areaRandom->getInt(60, 90);

        $plans = [];
        foreach ($hospitals as $index => $hospital) {
            $random = $this->streams->stream($volume->seed, $hospital->getPublicIdString(), $start->format('Y-m-d'), 'scenario:'.$scenario->name);
            $profile = $this->profiles->resolve($hospital, $volume->seed);
            // Extra cases are a share of the hospital's own rate, so a busier hospital gets more.
            $rate = $profile->dailyRate($volume) * $scenario->extraShare * $intensity;
            $dailyCounts = [];
            foreach ($days as $day) {
                $dailyCounts[$day] = $rate > 0.0 ? $random->getInt((int) floor($rate * 0.8), (int) ceil($rate * 1.2)) : 0;
            }
            $plans[] = new ScenarioHospitalPlan(
                hospital: $hospital,
                days: $days,
                dailyCounts: $dailyCounts,
                closures: $this->closures($scenario, $profile, $days, $lastDay, $random, $intensity),
                mciPatients: $mciShares[$index] ?? 0,
                mciStart: $mciStart,
                mciWindowMinutes: $mciWindow,
            );
        }

        return $plans;
    }

    /**
     * @param list<Hospital> $hospitals
     * @param bool           $rebuild   false leaves projections, views and KPIs to the caller (full fixture load)
     *
     * @return list<ScenarioHospitalResult>
     *
     * @throws \DomainException when the scenario cannot be applied
     */
    public function apply(ScenarioDefinition $scenario, FixtureVolume $volume, array $hospitals, \DateTimeImmutable $startDay, \DateTimeImmutable $lastDay, string $area, float $intensity = 1.0, bool $rebuild = true): array
    {
        // An invalid pattern package stops here, before the first import is written.
        $this->patterns->load($volume->patterns);
        $plans = $this->plan($scenario, $volume, $hospitals, $startDay, $lastDay, $area, $intensity);
        $mciId = sprintf('mci-scenario-%d-%s-%s', $volume->seed, hash('crc32b', $area), $startDay->format('Ymd'));

        $results = [];
        $allocationImports = [];
        $hospitalIds = [];
        foreach ($plans as $plan) {
            $results[] = $this->write($scenario, $volume, $plan, $mciId, $lastDay);
            $allocationImports[] = $results[array_key_last($results)]->importId;
            $hospitalIds[] = (int) $plan->hospital->getId();
            $this->entityManager->clear();
        }

        if ($rebuild && $volume->rebuildProjection) {
            $this->pipeline->afterImports($allocationImports, $hospitalIds);
        }

        return $results;
    }

    /**
     * Deletes every import of the scenario with the seed and start day, through the regular import
     * deletion, then rebuilds what depended on them: closure volume, views and the KPIs of their upload days.
     *
     * @return list<string> hospitals whose scenario imports were deleted
     *
     * @throws \DomainException when the scenario has no imports for the day
     */
    public function remove(ScenarioDefinition $scenario, int $seed, \DateTimeImmutable $startDay): array
    {
        /** @var list<array{id: int, hospital_id: int, name: string, day: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.hospital_id, h.name, CAST(i.created_at AS date) AS day
             FROM import i JOIN hospital h ON h.id = i.hospital_id
             WHERE i.file_path LIKE :marker ORDER BY i.id',
            ['marker' => SyntheticImportMarker::likeScenarioAnyHospital($scenario->name, $seed, $startDay->format('Y-m-d'))],
        );
        if ([] === $rows) {
            throw new \DomainException(sprintf('No imports of the scenario "%s" with seed %d start on %s.', $scenario->name, $seed, $startDay->format('Y-m-d')));
        }

        $refreshViews = false;
        foreach ($rows as $row) {
            $import = $this->entityManager->find(Import::class, $row['id']);
            if ($import instanceof Import) {
                $refreshViews = $this->importDeletion->delete($import, refreshMaterializedView: false) || $refreshViews;
            }
        }
        $this->entityManager->clear();

        $this->pipeline->afterRemoval(
            array_values(array_unique(array_column($rows, 'hospital_id'))),
            array_map(static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day), array_values(array_unique(array_column($rows, 'day')))),
        );

        return array_values(array_unique(array_column($rows, 'name')));
    }

    private function write(ScenarioDefinition $scenario, FixtureVolume $volume, ScenarioHospitalPlan $plan, string $mciId, \DateTimeImmutable $lastDay): ScenarioHospitalResult
    {
        $hospital = $this->entityManager->find(Hospital::class, (int) $plan->hospital->getId());
        $owner = $hospital?->getOwner();
        $dispatchArea = $hospital?->getDispatchArea();
        $state = $hospital?->getState();
        if (!$hospital instanceof Hospital || !$owner instanceof User || !$dispatchArea instanceof DispatchArea || !$state instanceof State) {
            throw new \DomainException(sprintf('%s needs an owner, a dispatch area and a state for a scenario.', (string) $plan->hospital->getName()));
        }

        $key = $hospital->getPublicIdString();
        $startDay = $plan->days[0];
        $uploadedAt = UploadTime::after($plan->lastDay(), $lastDay, $this->streams->stream($volume->seed, $key, $startDay, 'scenario:'.$scenario->name.':upload'));
        $closureImport = null;
        if ([] !== $plan->closures) {
            $closureImport = $this->createImport(ImportType::CLOSURE, sprintf('Scenario %s closures %s', $scenario->name, $startDay), $hospital, \count($plan->closures), SyntheticImportMarker::scenarioPath($scenario->name, $volume->seed, $key, $startDay, '-closures'), $owner, $uploadedAt);
            $this->closureWriter->write($plan->closures, (int) $hospital->getId(), (int) $closureImport->getId());
        }

        $rows = $plan->isMci() ? $plan->mciPatients : array_sum($plan->dailyCounts);
        $import = $this->createImport(ImportType::ALLOCATION, sprintf('Scenario %s %s', $scenario->name, $startDay), $hospital, $rows, SyntheticImportMarker::scenarioPath($scenario->name, $volume->seed, $key, $startDay), $owner, $uploadedAt);

        $profile = $this->profiles->resolve($hospital, $volume->seed);
        $pattern = $profile->applyTo($this->patterns->load($volume->patterns)->get($profile->segment))
            ->withDistribution('indication_normalized', ['_present' => 1.0] + $scenario->indications);
        $random = $this->streams->stream($volume->seed, $key, $startDay, 'scenario:'.$scenario->name.':rows');

        if ($plan->isMci()) {
            $this->writeMciPatients($plan, $pattern, $random, $hospital, $dispatchArea, $state, $import, $mciId);
        } else {
            $this->writeAllocations($plan, $pattern, $random, $hospital, $dispatchArea, $state, $import);
        }

        $windowStart = new \DateTimeImmutable($startDay.' 00:00:00');
        $windowEnd = new \DateTimeImmutable($plan->lastDay().' 23:59:59');
        $this->pipeline->processImport($import, $windowStart, $windowEnd);
        if ($closureImport instanceof Import) {
            $this->pipeline->processImport($closureImport, $windowStart, $windowEnd);
        }
        $this->entityManager->flush();

        return new ScenarioHospitalResult((string) $hospital->getName(), (int) $import->getId(), $plan->isMci() ? 0 : $rows, \count($plan->closures), $plan->mciPatients);
    }

    private function writeAllocations(ScenarioHospitalPlan $plan, DistributionPattern $pattern, Randomizer $random, Hospital $hospital, DispatchArea $dispatchArea, State $state, Import $import): void
    {
        $closed = ClosedDepartments::of($plan->closures, $this->lookups);
        $fixed = [
            'hospital_id' => (int) $hospital->getId(),
            'dispatch_area_id' => (int) $dispatchArea->getId(),
            'state_id' => (int) $state->getId(),
            'import_id' => (int) $import->getId(),
        ];
        $writer = new AllocationRowWriter($this->connection);
        foreach ($plan->dailyCounts as $day => $count) {
            for ($i = 0; $i < $count; ++$i) {
                $sampled = $this->sampler->sample($pattern, new \DateTimeImmutable($day.' 00:00:00'), new \DateTimeImmutable($day.' 23:59:59'), $random);
                $writer->add($sampled, $fixed, $closed->isClosed((int) $sampled->department->getId(), $sampled->createdAt));
            }
        }
        $writer->flush();
    }

    /**
     * Patients of a mass casualty incident: urgency 1, resuscitation room and physician, all within the window.
     */
    private function writeMciPatients(ScenarioHospitalPlan $plan, DistributionPattern $pattern, Randomizer $random, Hospital $hospital, DispatchArea $dispatchArea, State $state, Import $import, string $mciId): void
    {
        for ($i = 0; $i < $plan->mciPatients; ++$i) {
            $sampled = $this->sampler->sample($pattern, $plan->mciStart, $plan->mciStart, $random);
            $createdAt = $plan->mciStart->modify(sprintf('+%d minutes', $random->getInt(0, $plan->mciWindowMinutes)));
            $this->entityManager->persist(
                new MciCase()
                    ->setHospital($hospital)
                    ->setDispatchArea($dispatchArea)
                    ->setState($state)
                    ->setImport($import)
                    ->setCreatedAt($createdAt)
                    ->setArrivalAt($createdAt->modify(sprintf('+%d minutes', $random->getInt(15, 45))))
                    ->setGender($sampled->gender)
                    ->setAge($sampled->age)
                    ->setUrgency(AllocationUrgency::EMERGENCY)
                    ->setRequiresResus(true)
                    ->setRequiresCathlab(false)
                    ->setIsCPR($sampled->isCpr)
                    ->setIsVentilated($sampled->isVentilated)
                    ->setIsShock($sampled->isShock)
                    ->setIsPregnant(false)
                    ->setIsWithPhysician(true)
                    ->setTransportType($sampled->transportType)
                    ->setSpeciality($sampled->speciality)
                    ->setDepartment($sampled->department)
                    ->setDepartmentWasClosed(false)
                    ->setOccasion($sampled->occasion)
                    ->setIndicationRaw($sampled->indicationRaw)
                    ->setIndicationNormalized($sampled->indicationNormalized)
                    ->setMciId($mciId)
                    ->setMciTitle(sprintf('Synthetic MCI %s', $plan->days[0])),
            );
        }
        $this->entityManager->flush();
    }

    /**
     * @return list<string> days (Y-m-d) from the start, at most until the last day of the period
     */
    private function days(ScenarioDefinition $scenario, \DateTimeImmutable $start, \DateTimeImmutable $lastDay, Randomizer $random): array
    {
        $count = $random->getInt(...$scenario->days);
        $days = [];
        for ($day = $start, $i = 0; $i < $count && $day <= $lastDay; $day = $day->modify('+1 day'), ++$i) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Extra closures, only in departments the hospital offers.
     *
     * @param list<string> $days
     *
     * @return list<PlannedClosure>
     */
    private function closures(ScenarioDefinition $scenario, HospitalProfile $profile, array $days, \DateTimeImmutable $lastDay, Randomizer $random, float $intensity): array
    {
        $departments = array_values(array_filter($scenario->closedDepartments, $profile->offers(...)));
        if ([] === $departments || $scenario->closuresPerHospitalAndWeek <= 0.0) {
            return [];
        }

        $count = (int) round($scenario->closuresPerHospitalAndWeek * (float) \count($days) / 7.0 * $intensity);
        $end = \DateTimeImmutable::createFromInterface($lastDay)->setTime(23, 59, 59);
        $closures = [];
        for ($i = 0; $i < $count; ++$i) {
            $startsAt = new \DateTimeImmutable($days[$random->getInt(0, \count($days) - 1)])->setTime($random->getInt(8, 20), $random->getInt(0, 59));
            $endsAt = min($startsAt->modify(sprintf('+%d minutes', $random->getInt(6 * 60, 24 * 60))), $end);
            $closures[] = new PlannedClosure(
                $departments[$random->getInt(0, \count($departments) - 1)],
                $startsAt,
                $endsAt,
                ClosureCareLevel::INPATIENT,
                ClosureReason::NO_BED_CAPACITY,
                null,
            );
        }

        return $closures;
    }

    /**
     * Splits the patients over the hospitals: every hospital one while patients are left, the rest drawn
     * by tier and size (full care and large hospitals take more).
     *
     * @param list<Hospital> $hospitals
     *
     * @return list<int>
     */
    private function spread(int $patients, array $hospitals, Randomizer $random): array
    {
        $weights = array_map(static fn (Hospital $hospital): float => self::MCI_TIER_WEIGHT[($hospital->getTier() ?? HospitalTier::BASIC)->value]
            * self::MCI_SIZE_WEIGHT[($hospital->getSize() ?? HospitalSize::MEDIUM)->value], $hospitals);
        $total = array_sum($weights);

        $shares = array_fill(0, \count($hospitals), 0);
        for ($i = 0; $i < $patients; ++$i) {
            if ($i < \count($hospitals)) {
                ++$shares[$i];
                continue;
            }
            $target = $random->nextFloat() * $total;
            foreach ($weights as $index => $weight) {
                $target -= $weight;
                if ($target < 0.0 || $index === array_key_last($weights)) {
                    ++$shares[$index];
                    break;
                }
            }
        }

        return $shares;
    }

    private function isApplied(ScenarioDefinition $scenario, Hospital $hospital, \DateTimeImmutable $start): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM import WHERE hospital_id = :hospital AND file_path LIKE :marker LIMIT 1',
            ['hospital' => (int) $hospital->getId(), 'marker' => SyntheticImportMarker::likeScenario($scenario->name, $hospital->getPublicIdString(), $start->format('Y-m-d'))],
        );
    }

    private function createImport(ImportType $type, string $name, Hospital $hospital, int $rowCount, string $path, User $createdBy, \DateTimeImmutable $uploadedAt): Import
    {
        $import = new Import()
            ->setName($name)
            ->setCreatedAt($uploadedAt)
            ->setHospital($hospital)
            ->setStatus(ImportStatus::COMPLETED)
            ->setType($type)
            ->setFilePath($path)
            ->setFileExtension('.csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(1)
            ->setRowCount($rowCount)
            ->setRowsPassed($rowCount)
            ->setRowsRejected(0)
            ->setRunCount(1)
            ->setRunTime(1)
            ->setCreatedBy($createdBy);

        $this->entityManager->persist($import);
        $this->entityManager->flush();

        return $import;
    }
}
