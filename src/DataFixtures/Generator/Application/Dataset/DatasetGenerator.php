<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Catalog\CatalogMismatchException;
use App\DataFixtures\Generator\Application\GeneratedMonth;
use App\DataFixtures\Generator\Application\GenerationPreflight;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
use App\DataFixtures\Generator\Application\ImportBatchPlanner;
use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunRequest;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\Run\GenerationRunStage;
use App\DataFixtures\Generator\Application\Run\GenerationRunTracker;
use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
use App\DataFixtures\Generator\Infrastructure\Query\HospitalPeriodDataQuery;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Generates synthetic allocations and closures for one, several or all participating hospitals over a shared
 * period (#877, ADR 022). The console command `app:generate:dataset` and the demo wizard (#791) use it:
 *
 * 1. prepare(): checks everything before a row is written and returns the plan per hospital (ready or blocked,
 *    months to generate, keep or replace, planned allocations, profile).
 * 2. start(): records a generation run with every setting that shapes the data, so it can be repeated.
 * 3. generate(): writes the months synchronously from the recorded settings, then hands each hospital to the
 *    asynchronous projections of the run (GenerationRunTracker::generationFinished()).
 *
 * Real imports are never touched; a hospital with real data in the period is blocked. Hospital master data is
 * never written. A hospital that fails is rolled back as a whole, so retrying it in mode Fill writes no duplicates.
 */
final readonly class DatasetGenerator
{
    /** Environments where synthetic data may be written (ADR 022, ADR 023). */
    public const array ALLOWED_ENVIRONMENTS = ['dev', 'test', 'demo'];

    public const string ENGINE = 'dataset';

    /** A hospital failed while it was generated and was rolled back. */
    public const string REASON_FAILED = 'generation_failed';

    /** A hospital was blocked when its turn came (data or master data changed after the plan). */
    public const string REASON_BLOCKED = 'blocked';

    /** A hospital of a run whose process stopped during generation. */
    public const string REASON_ABANDONED = 'abandoned';

    public function __construct(
        private FixtureVolumeResolver $volumeResolver,
        private GenerationPreflight $preflight,
        private HospitalMonthGenerator $generator,
        private ImportBatchPlanner $planner,
        private HospitalPeriodDataQuery $periodData,
        private GenerationRunTracker $runs,
        private ImportDeletionService $importDeletion,
        private SyntheticDataPipeline $pipeline,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private Connection $connection,
        private RoleHierarchyInterface $roles,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * Checks the request and plans every hospital. Writes nothing.
     */
    public function prepare(DatasetRequest $request): DatasetPlan
    {
        $blockers = [];
        if (!\in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $blockers[] = \sprintf('Synthetic data is only generated in %s, not in "%s".', implode(', ', self::ALLOWED_ENVIRONMENTS), $this->environment);
        }

        $volume = $this->volumeResolver->resolve();
        try {
            $volume = $this->volume($request->seed ?? $volume->seed, $request->patterns ?? $volume->patterns);
            $this->preflight->check($volume);
        } catch (InvalidPatternPackageException|CatalogMismatchException|\InvalidArgumentException $exception) {
            $blockers[] = $exception->getMessage();
        }

        $lastDay = $request->period->lastDay($request->lastDay ?? $volume->lastDay($this->clock));
        if ($request->period->firstDay() > $lastDay) {
            $blockers[] = \sprintf('The period starts after the last day that can get data (%s).', $lastDay->format('Y-m-d'));
        }

        $hospitals = $this->hospitals($request);
        if (GenerationRunScope::AllParticipating === $request->scope && [] === $hospitals) {
            $blockers[] = 'No hospital takes part: mark hospitals as participating first.';
        }
        $known = array_values(array_filter($hospitals, static fn (Hospital|int $hospital): bool => $hospital instanceof Hospital));
        $busy = $this->runs->hospitalsBeingGenerated(array_map(static fn (Hospital $hospital): int => (int) $hospital->getId(), $known));

        $plans = [];
        foreach ($hospitals as $hospital) {
            $plans[] = $hospital instanceof Hospital
                ? $this->hospitalPlan($hospital, $request->period, $request->existing, $request->selectionFor((int) $hospital->getId()), $volume, $lastDay, \in_array((int) $hospital->getId(), $busy, true))
                : new DatasetHospitalPlan($hospital, \sprintf('#%d', $hospital), ['unknown hospital']);
        }

        return new DatasetPlan($request, $volume->seed, $volume->patterns, $lastDay, $blockers, $plans);
    }

    /**
     * Records the run of a runnable plan. Hospitals without work (every month kept) are left out of the run.
     *
     * @return string run id
     *
     * @throws \DomainException when the plan is not runnable
     */
    public function start(DatasetPlan $plan): string
    {
        $blockers = $plan->runBlockers();
        if ([] !== $blockers) {
            throw new \DomainException('The dataset cannot be generated: '.implode(' ', $blockers));
        }

        $request = $plan->request;
        $volume = $this->volume($plan->seed, $plan->patterns);
        $ids = array_map(static fn (DatasetHospitalPlan $hospital): int => $hospital->hospitalId, $plan->withWork());

        $selections = [];
        foreach ($ids as $id) {
            $selection = $request->selectionFor($id);
            if ($selection instanceof ProfileSelection) {
                $selections[(string) $id] = ProfileSelectionCodec::encode($selection);
            }
        }
        $skipped = [];
        foreach ($plan->skipped as $hospital) {
            $skipped[$hospital->name] = implode('; ', $hospital->blockers);
        }

        return $this->runs->start(new GenerationRunRequest(
            scope: $request->scope,
            hospitalIds: $ids,
            seed: $plan->seed,
            fromMonth: $request->period->from,
            toMonth: $request->period->to,
            settings: [
                'engine' => self::ENGINE,
                'existing' => $request->existing->value,
                'patterns' => $plan->patterns,
                'last_day' => $plan->lastDay->format('Y-m-d'),
                'fixture_profile' => $volume->profile,
                'fixture_scale' => $volume->scale,
                'selections' => $selections,
                'skipped' => $skipped,
                'retry_of' => $request->retryOf,
            ],
            trigger: $request->trigger,
            notify: $request->notify,
            initiatedById: $request->initiatedById,
        ));
    }

    /**
     * Generates the pending hospitals of a started run from its recorded settings, then hands them to the
     * asynchronous stage. Each hospital is checked again first; one that fails is rolled back and reported,
     * the others go on.
     *
     * @throws \DomainException when the run is unknown, not a dataset run, already generated or cannot be reproduced
     */
    public function generate(string $runId, ?DatasetProgress $progress = null): DatasetResult
    {
        $run = $this->runs->get($runId) ?? throw new \DomainException(\sprintf('Unknown generation run "%s".', $runId));
        if (self::ENGINE !== ($run->settings['engine'] ?? null)) {
            throw new \DomainException(\sprintf('The run %s was not started by the dataset generator.', $runId));
        }
        if (GenerationRunStage::Generating !== $run->stage) {
            throw new \DomainException(\sprintf('The run %s has already been generated.', $runId));
        }

        $volume = $this->recordedVolume($run);
        $period = DatasetPeriod::between((string) $run->fromMonth, (string) $run->toMonth);
        $existing = ExistingData::from($this->string($run->settings, 'existing'));
        $lastDay = new \DateTimeImmutable($this->string($run->settings, 'last_day'));
        $selections = \is_array($run->settings['selections'] ?? null) ? $run->settings['selections'] : [];

        $preflightError = null;
        try {
            $this->preflight->check($volume);
        } catch (InvalidPatternPackageException|CatalogMismatchException|\InvalidArgumentException $exception) {
            $preflightError = $exception->getMessage();
        }

        $removedDays = [];
        $lostData = [];
        foreach ($run->hospitals as $entry) {
            if (GenerationRunHospitalStatus::Pending !== $entry->status) {
                continue;
            }
            $hospitalId = $entry->hospitalId;
            $this->runs->hospitalStarted($runId, $hospitalId);
            $selection = \is_array($selections[(string) $hospitalId] ?? null) ? ProfileSelectionCodec::decode($selections[(string) $hospitalId]) : null;

            $hospital = $this->entityManager->find(Hospital::class, $hospitalId);
            $plan = $hospital instanceof Hospital
                ? $this->hospitalPlan($hospital, $period, $existing, $selection, $volume, $lastDay, false)
                : new DatasetHospitalPlan($hospitalId, $entry->hospitalName, ['unknown hospital']);
            $progress?->hospitalStarted($plan);

            $written = [];
            $days = [];
            try {
                if (null !== $preflightError) {
                    throw new \DomainException($preflightError);
                }
                if (!$plan->isReady()) {
                    throw new \DomainException('blocked: '.implode('; ', $plan->blockers));
                }

                $data = $this->periodData->find($hospitalId, $period);
                array_push($days, ...$this->delete($plan->scenarioImports));
                foreach ($plan->keep as $month) {
                    $unprojected = $data->unprojected($month);
                    if (null !== $unprojected) {
                        $this->runs->monthKept($runId, $hospitalId, $unprojected['allocations'], $unprojected['closures']);
                    }
                }
                foreach ($plan->generate as $month) {
                    if (\in_array($month, $plan->replace, true)) {
                        array_push($days, ...$this->delete($data->generatedImportIds($month)));
                    }
                    $hospital = $this->entityManager->find(Hospital::class, $hospitalId) ?? throw new \DomainException('The hospital was deleted.');
                    $generated = $this->connection->transactional(fn (): GeneratedMonth => $this->generator->generate($volume, $hospital, $month, $lastDay, $selection));
                    $written[] = $generated->importId;
                    $written[] = $generated->closureImportId;
                    $this->runs->monthGenerated($runId, $hospitalId, $generated);
                    $progress?->monthGenerated($plan, $month, $generated);
                    $this->entityManager->clear();
                }
                array_push($removedDays, ...$days);
                $progress?->hospitalFinished($plan);
            } catch (\Exception $exception) {
                $this->resetEntityManager();
                // The hospital fails as a whole: months it wrote in this run go again, so a retry starts clean.
                array_push($days, ...$this->delete($written));
                $this->runs->hospitalRolledBack($runId, $hospitalId, $plan->isReady() ? self::REASON_FAILED : self::REASON_BLOCKED, $exception->getMessage());
                $progress?->hospitalFailed($plan, $exception->getMessage());
                if ([] !== $days) {
                    $lostData[$hospitalId] = $days;
                }
            } finally {
                $this->entityManager->clear();
            }
        }

        $this->runs->importsRemoved($runId, array_values(array_unique($removedDays)));
        if ([] !== $lostData) {
            // These hospitals lost generated data and get none back, so no asynchronous stage rebuilds them.
            $this->pipeline->afterRemoval(
                array_keys($lostData),
                array_map(static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day), array_values(array_unique(array_merge(...array_values($lostData))))),
            );
        }
        $handedOver = $this->runs->generationFinished($runId);

        $skipped = \is_array($run->settings['skipped'] ?? null) ? array_map(strval(...), $run->settings['skipped']) : [];

        return new DatasetResult($runId, $handedOver, $this->runs->get($runId), $skipped);
    }

    /**
     * A request that generates the hospitals again that failed during generation of a dataset run, with the run's
     * recorded seed, period, patterns and profiles, in mode Fill (#877). Complete months are kept, so nothing is
     * written twice. A stale run that is still generating (its process stopped) is closed first: its open
     * hospitals fail with `abandoned`.
     *
     * Hospitals that failed in the asynchronous stage keep their data; `app:generate:run:retry` projects them again.
     *
     * @throws \DomainException when the run is unknown, not a dataset run, still active or has nothing to generate again
     */
    public function retryRequest(string $runId): DatasetRequest
    {
        $run = $this->runs->get($runId) ?? throw new \DomainException(\sprintf('Unknown generation run "%s".', $runId));
        if (self::ENGINE !== ($run->settings['engine'] ?? null)) {
            throw new \DomainException(\sprintf('The run %s was not started by the dataset generator; start a new run.', $runId));
        }
        if (GenerationRunStage::Generating === $run->stage) {
            if (!$run->isStale($this->clock->now())) {
                throw new \DomainException('The run is still generating. Retry once its synchronous stage ended, or after 30 minutes without progress.');
            }
            foreach ($run->hospitals as $hospital) {
                if (\in_array($hospital->status, [GenerationRunHospitalStatus::Pending, GenerationRunHospitalStatus::Generating], true)) {
                    $this->runs->hospitalRolledBack($runId, $hospital->hospitalId, self::REASON_ABANDONED, 'The generating process stopped.');
                }
            }
            $this->runs->generationFinished($runId);
            $run = $this->runs->get($runId) ?? $run;
        }

        $ids = [];
        foreach ($run->hospitals as $hospital) {
            if (GenerationRunHospitalStatus::Failed === $hospital->status && 0 === $hospital->months && [] === $hospital->allocationImportIds) {
                $ids[] = $hospital->hospitalId;
            }
        }
        if ([] === $ids) {
            throw new \DomainException('No hospital of the run failed during generation.');
        }

        $selections = [];
        $recorded = \is_array($run->settings['selections'] ?? null) ? $run->settings['selections'] : [];
        foreach ($ids as $id) {
            if (\is_array($recorded[(string) $id] ?? null)) {
                $selections[$id] = ProfileSelectionCodec::decode($recorded[(string) $id]);
            }
        }

        return new DatasetRequest(
            scope: 1 === \count($ids) ? GenerationRunScope::One : GenerationRunScope::Selected,
            hospitalIds: $ids,
            period: DatasetPeriod::between((string) $run->fromMonth, (string) $run->toMonth),
            seed: $run->seed,
            existing: ExistingData::Fill,
            selections: $selections,
            patterns: $this->string($run->settings, 'patterns'),
            trigger: $run->trigger,
            notify: $run->notify,
            initiatedById: $run->initiatedById,
            retryOf: $runId,
            lastDay: new \DateTimeImmutable($this->string($run->settings, 'last_day')),
        );
    }

    /**
     * A FixtureVolume with the configured preset and the given seed and pattern source.
     *
     * @throws \InvalidArgumentException when the preset is unknown
     */
    private function volume(int $seed, string $patterns): FixtureVolume
    {
        return $this->volumeResolver->resolve()->withSeed($seed)->withPatterns($patterns);
    }

    /**
     * The volume a run was planned with. The preset and scale shape the volume of drawn profiles, so a run is
     * only generated with the ones it recorded.
     */
    private function recordedVolume(GenerationRunView $run): FixtureVolume
    {
        $volume = $this->volume($run->seed, $this->string($run->settings, 'patterns'));
        $profile = $this->string($run->settings, 'fixture_profile');
        $scale = $run->settings['fixture_scale'] ?? null;
        if ($volume->profile !== $profile || !\is_numeric($scale) || abs($volume->scale - (float) $scale) > 1e-9) {
            throw new \DomainException(\sprintf('The run was planned with FIXTURES_PROFILE=%s and FIXTURES_SCALE=%s; this process uses %s and %s.', $profile, \is_scalar($scale) ? (string) $scale : '?', $volume->profile, (string) $volume->scale));
        }

        return $volume;
    }

    /**
     * @return list<Hospital|int> hospitals of the request in generation order; unknown ids stay as int
     */
    private function hospitals(DatasetRequest $request): array
    {
        $repository = $this->entityManager->getRepository(Hospital::class);
        if (GenerationRunScope::AllParticipating === $request->scope) {
            return $repository->findBy(['isParticipating' => true], ['name' => 'ASC', 'id' => 'ASC']);
        }

        return array_map(static fn (int $id): Hospital|int => $repository->find($id) ?? $id, $request->hospitalIds);
    }

    private function hospitalPlan(Hospital $hospital, DatasetPeriod $period, ExistingData $existing, ?ProfileSelection $selection, FixtureVolume $volume, \DateTimeImmutable $lastDay, bool $busy): DatasetHospitalPlan
    {
        $hospitalId = (int) $hospital->getId();
        $name = (string) $hospital->getName();
        $blockers = $this->masterDataBlockers($hospital);
        if ($busy) {
            $blockers[] = 'another generation run is still writing data for it';
        }

        $profile = null;
        try {
            $profile = $this->generator->profile($volume, $hospital, $selection);
        } catch (\DomainException $exception) {
            $blockers[] = $exception->getMessage();
        }

        $data = $this->periodData->find($hospitalId, $period);
        $realMonths = $data->realMonths();
        if ([] !== $realMonths) {
            $blockers[] = \sprintf('it has real imports in %s; real data is never mixed with or replaced by generated data', implode(', ', $realMonths));
        }

        $generate = [];
        $keep = [];
        $replace = [];
        $conflicts = [];
        $notes = [];
        foreach ($period->months() as $month) {
            if (ImportBatchPlanner::firstDay($month) > $lastDay) {
                continue;
            }
            if (!$data->hasGenerated($month)) {
                $generate[] = $month;
                continue;
            }
            if (ExistingData::Refuse === $existing) {
                $conflicts[] = $month;
            } elseif (ExistingData::Fill === $existing && $data->isComplete($month)) {
                $keep[] = $month;
                if ($data->seeds($month) !== [$volume->seed]) {
                    $notes[] = \sprintf('%s keeps data of seed %s', $month, implode(', ', $data->seeds($month)));
                }
            } else {
                $generate[] = $month;
                $replace[] = $month;
            }
        }
        if ([] !== $conflicts) {
            $blockers[] = \sprintf('it already has generated data in %s; choose fill or replace', implode(', ', $conflicts));
        }

        $scenarioImports = [];
        $scenarioMonths = $data->scenarioMonths();
        $uncovered = array_values(array_diff($scenarioMonths, $keep));
        if ([] !== $scenarioMonths && ExistingData::Refuse === $existing) {
            $blockers[] = \sprintf('it has generated scenario imports in %s; choose replace', implode(', ', $scenarioMonths));
        } elseif ([] !== $scenarioMonths && ExistingData::Fill === $existing && [] !== $uncovered) {
            $blockers[] = \sprintf('scenario imports in %s lie on months without complete generated data; choose replace', implode(', ', $uncovered));
        } elseif ([] !== $scenarioMonths && ExistingData::Fill === $existing) {
            $notes[] = \sprintf('%d scenario import(s) stay', \count($data->scenarios));
        } elseif ([] !== $scenarioMonths) {
            $scenarioImports = $data->scenarioImportIds();
        }

        $planned = 0;
        if ([] === $blockers && $profile instanceof \App\DataFixtures\Generator\Application\HospitalProfile) {
            foreach ($generate as $month) {
                $planned += $this->planner->planMonth($volume, $hospital, $month, $lastDay, $profile)->allocationCount();
            }
        }

        return new DatasetHospitalPlan(
            hospitalId: $hospitalId,
            name: $name,
            blockers: $blockers,
            generate: $generate,
            keep: $keep,
            replace: $replace,
            scenarioImports: $scenarioImports,
            plannedAllocations: $planned,
            profile: $profile instanceof \App\DataFixtures\Generator\Application\HospitalProfile ? \sprintf('%s, %.1f/day, %s %% closed', $profile->configuration, $profile->dailyRate($volume), rtrim(rtrim(number_format($profile->closureShare * 100.0, 1, '.', ''), '0'), '.')) : '',
            notes: $notes,
        );
    }

    /**
     * What the hospital lacks for generated data; the demo wizard sets it up beforehand (#791).
     *
     * @return list<string>
     */
    private function masterDataBlockers(Hospital $hospital): array
    {
        $blockers = [];
        if (!$hospital->isParticipating()) {
            $blockers[] = 'it does not take part (participation is off)';
        }
        $owner = $hospital->getOwner();
        if (!$owner instanceof User) {
            $blockers[] = 'it has no owner (a participant account that receives the data)';
        } elseif (!\in_array('ROLE_PARTICIPANT', $this->roles->getReachableRoleNames($owner->getRoles()), true)) {
            $blockers[] = \sprintf('its owner %s has no participant access (ROLE_PARTICIPANT)', $owner->getUserIdentifier());
        }
        if (!$hospital->getDispatchArea() instanceof DispatchArea) {
            $blockers[] = 'it has no dispatch area';
        }
        if (!$hospital->getState() instanceof State) {
            $blockers[] = 'it has no state';
        }

        return $blockers;
    }

    /**
     * Deletes imports through the regular import deletion (rows, MCI cases, projection rows).
     *
     * @param list<int> $importIds
     *
     * @return list<string> upload days (Y-m-d) of the deleted imports
     */
    private function delete(array $importIds): array
    {
        $days = [];
        foreach ($importIds as $importId) {
            $import = $this->entityManager->find(Import::class, $importId);
            if (!$import instanceof Import) {
                continue;
            }
            $days[] = $import->getCreatedAt()->format('Y-m-d');
            $this->importDeletion->delete($import, refreshMaterializedView: false);
        }
        $this->entityManager->clear();

        return array_values(array_unique($days));
    }

    private function resetEntityManager(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
        }
        $this->entityManager->clear();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function string(array $settings, string $key): string
    {
        $value = $settings[$key] ?? null;
        if (!\is_string($value)) {
            throw new \DomainException(\sprintf('The run settings lack "%s".', $key));
        }

        return $value;
    }
}
