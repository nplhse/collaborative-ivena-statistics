<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run\MessageHandler;

use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunNotifierInterface;
use App\DataFixtures\Generator\Application\Run\GenerationRunReason;
use App\DataFixtures\Generator\Application\Run\GenerationRunStage;
use App\DataFixtures\Generator\Application\Run\GenerationRunTracker;
use App\DataFixtures\Generator\Application\Run\Message\FinishGenerationRun;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Final stage of a generation run: materialized views and KPIs once for all hospitals, then the terminal
 * status. Only the process whose terminal transition wins notifies, and only completed hospitals.
 */
#[AsMessageHandler]
final readonly class FinishGenerationRunHandler
{
    public function __construct(
        private GenerationRunStore $store,
        private SyntheticDataPipeline $pipeline,
        private GenerationRunNotifierInterface $notifier,
        private ClockInterface $clock,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    public function __invoke(FinishGenerationRun $message): void
    {
        $this->jobRun->addMetrics(['generation_run_id' => $message->runId]);

        $run = $this->store->find($message->runId);
        if (!$run instanceof \App\DataFixtures\Generator\Application\Run\GenerationRunView || GenerationRunStage::Finishing !== $run->stage || $run->status->isTerminal()) {
            $this->jobRun->markSkipped('not_finishing');

            return;
        }

        $importIds = [];
        foreach ($run->hospitals as $hospital) {
            if (GenerationRunHospitalStatus::Completed === $hospital->status) {
                array_push($importIds, ...$hospital->allocationImportIds);
            }
        }
        $removed = $run->settings[GenerationRunTracker::REMOVED_UPLOAD_DAYS] ?? [];
        $removedDays = \is_array($removed) ? array_values(array_map(static fn (mixed $day): \DateTimeImmutable => new \DateTimeImmutable(\is_string($day) ? $day : ''), $removed)) : [];
        if ([] !== $importIds || [] !== $removedDays) {
            $this->pipeline->finishImports($importIds, $removedDays);
        }

        $status = $this->store->finish($message->runId, GenerationRunReason::HOSPITAL_FAILED, $this->clock->now());
        if (!$status instanceof \App\DataFixtures\Generator\Application\Run\GenerationRunStatus) {
            return;
        }
        $this->jobRun->addMetrics(['status' => $status->value]);

        $finished = $this->store->find($message->runId);
        if ($finished instanceof \App\DataFixtures\Generator\Application\Run\GenerationRunView) {
            $this->jobRun->addMetrics(['notifications' => $this->notifier->notify($finished)]);
        }
    }
}
