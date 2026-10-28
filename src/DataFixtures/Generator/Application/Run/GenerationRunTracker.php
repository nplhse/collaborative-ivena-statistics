<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

use App\DataFixtures\Generator\Application\GeneratedMonth;
use App\DataFixtures\Generator\Application\Run\Message\ProjectGeneratedHospital;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records a generation run across its synchronous and asynchronous stages (#791, ADR 023).
 *
 * The caller generates the data synchronously and reports each hospital and month here. generationFinished()
 * hands the generated hospitals to the asynchronous stage (one ProjectGeneratedHospital per hospital) and
 * returns; the caller does not wait for projections. Without a run, generation keeps using
 * SyntheticDataPipeline::afterImports() synchronously.
 */
final readonly class GenerationRunTracker
{
    /** Settings key with the upload days (Y-m-d) of imports the run deleted. */
    public const string REMOVED_UPLOAD_DAYS = 'removed_upload_days';

    public function __construct(
        private GenerationRunStore $store,
        private MessageBusInterface $messageBus,
        private GenerationRunCompletion $completion,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string run id (UUID)
     */
    public function start(GenerationRunRequest $request): string
    {
        return $this->store->create($request, $this->clock->now());
    }

    public function hospitalStarted(string $runId, int $hospitalId): void
    {
        $this->store->hospitalStarted($runId, $hospitalId, $this->clock->now());
    }

    public function monthGenerated(string $runId, int $hospitalId, GeneratedMonth $month): void
    {
        $this->store->monthGenerated($runId, $hospitalId, $month->importId, $month->closureImportId, $month->allocations, $month->closures, $month->mciCases, $this->clock->now());
    }

    /**
     * A complete month the hospital already had, handed to the asynchronous stage for its projections without
     * counting as generated (#877).
     */
    public function monthKept(string $runId, int $hospitalId, int $allocationImportId, int $closureImportId): void
    {
        $this->store->monthKept($runId, $hospitalId, $allocationImportId, $closureImportId, $this->clock->now());
    }

    /**
     * Upload days of imports the run deleted before generating again; the final stage aggregates their KPIs
     * together with those of the new imports.
     *
     * @param list<string> $days Y-m-d
     */
    public function importsRemoved(string $runId, array $days): void
    {
        if ([] === $days) {
            return;
        }
        $known = $this->get($runId)?->settings[self::REMOVED_UPLOAD_DAYS] ?? [];
        $days = array_values(array_unique([...(\is_array($known) ? array_map(strval(...), $known) : []), ...$days]));
        sort($days);
        $this->store->mergeSettings($runId, [self::REMOVED_UPLOAD_DAYS => $days], $this->clock->now());
    }

    /**
     * @param list<int> $hospitalIds
     *
     * @return list<int> hospitals another run is still generating (runs idle for 30 minutes count as abandoned)
     */
    public function hospitalsBeingGenerated(array $hospitalIds): array
    {
        return $this->store->hospitalsBeingGenerated($hospitalIds, $this->clock->now()->modify('-30 minutes'));
    }

    /**
     * The hospital gets no asynchronous stage; months it generated before stay in the database and are listed
     * by `app:generate:run:show`.
     */
    public function hospitalFailed(string $runId, int $hospitalId, string $reasonCode, ?string $message = null): void
    {
        $this->store->failHospital($runId, $hospitalId, $reasonCode, $message, $this->clock->now());
    }

    /**
     * Like hospitalFailed(), for a hospital whose months of this run were deleted again (#877): its counters and
     * imports are cleared, so neither the asynchronous stage nor `app:generate:run:retry` projects it.
     */
    public function hospitalRolledBack(string $runId, int $hospitalId, string $reasonCode, ?string $message = null): void
    {
        $this->store->failHospital($runId, $hospitalId, $reasonCode, $message, $this->clock->now(), discardGenerated: true);
    }

    /**
     * Ends the synchronous stage and dispatches the asynchronous one. Hospitals that were never reported or
     * generated no month fail with `nothing_generated`.
     *
     * @return list<int> hospitals handed to the asynchronous stage
     */
    public function generationFinished(string $runId): array
    {
        $now = $this->clock->now();
        $generated = $this->store->endGeneration($runId, GenerationRunReason::NOTHING_GENERATED, $now);
        if ([] === $generated) {
            $this->store->failRun($runId, GenerationRunReason::NOTHING_GENERATED, $now);

            return [];
        }

        foreach ($generated as $hospitalId) {
            $this->messageBus->dispatch(new ProjectGeneratedHospital($runId, $hospitalId));
        }
        // When every message was handled synchronously (tests), the last one already finished the run.
        $this->completion->check($runId);

        return $generated;
    }

    public function get(string $runId): ?GenerationRunView
    {
        return $this->store->find($runId);
    }
}
