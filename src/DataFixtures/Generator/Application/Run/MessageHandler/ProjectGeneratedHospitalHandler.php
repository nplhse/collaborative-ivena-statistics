<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run\MessageHandler;

use App\DataFixtures\Generator\Application\Run\GenerationRunCompletion;
use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalView;
use App\DataFixtures\Generator\Application\Run\Message\ProjectGeneratedHospital;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous stage of one hospital of a generation run.
 *
 * A lock per run and hospital keeps a second delivery (a retry command, a redelivered message) from projecting
 * the same hospital in parallel; it is released with its process. The status claim makes a delivery for a
 * completed or failed hospital a no-op. Errors are rethrown, so Messenger retries the message; once it gives up,
 * GenerationRunRetriesExhaustedSubscriber fails the hospital.
 */
#[AsMessageHandler]
final readonly class ProjectGeneratedHospitalHandler
{
    public function __construct(
        private GenerationRunStore $store,
        private SyntheticDataPipeline $pipeline,
        private GenerationRunCompletion $completion,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    public function __invoke(ProjectGeneratedHospital $message): void
    {
        $this->jobRun->addMetrics(['generation_run_id' => $message->runId, 'hospital_id' => $message->hospitalId]);

        $lock = $this->lockFactory->createLock(self::lockKey($message->runId, $message->hospitalId), null);
        if (!$lock->acquire(false)) {
            $this->logger->info('generation_run.hospital_locked', ['run' => $message->runId, 'hospital_id' => $message->hospitalId]);
            $this->jobRun->markSkipped('locked');

            return;
        }

        try {
            if (!$this->store->claimProjection($message->runId, $message->hospitalId, $this->clock->now())) {
                $this->jobRun->markSkipped('not_claimable');

                return;
            }

            $importIds = $this->hospital($message)->allocationImportIds ?? [];
            $this->pipeline->projectHospital(
                $message->hospitalId,
                $importIds,
                fn (string $step, int $done, int $total) => $this->store->projectionStep($message->runId, $message->hospitalId, $step, $done, $total, $this->clock->now()),
            );
            if (!$this->store->completeHospital($message->runId, $message->hospitalId, $this->clock->now())) {
                // Failed meanwhile, e.g. by a given-up earlier delivery; the run keeps that outcome.
                $this->logger->warning('generation_run.hospital_not_completed', ['run' => $message->runId, 'hospital_id' => $message->hospitalId]);
            }
            $this->jobRun->addMetrics(['imports' => \count($importIds)]);
        } finally {
            $lock->release();
        }

        $this->completion->check($message->runId);
    }

    public static function lockKey(string $runId, int $hospitalId): string
    {
        return \sprintf('generation_run_hospital.%s.%d', $runId, $hospitalId);
    }

    private function hospital(ProjectGeneratedHospital $message): ?GenerationRunHospitalView
    {
        foreach ($this->store->find($message->runId)->hospitals ?? [] as $hospital) {
            if ($hospital->hospitalId === $message->hospitalId) {
                return $hospital;
            }
        }

        return null;
    }
}
