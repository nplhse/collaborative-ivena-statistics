<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Run;

use App\DataFixtures\Generator\Application\Run\GenerationRunCompletion;
use App\DataFixtures\Generator\Application\Run\GenerationRunReason;
use App\DataFixtures\Generator\Application\Run\Message\FinishGenerationRun;
use App\DataFixtures\Generator\Application\Run\Message\ProjectGeneratedHospital;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Fails the hospital (reason retries_exhausted) once Messenger gives up on its asynchronous stage, and lets the
 * run finish without it. A given-up final stage fails the whole run. While a retry follows, nothing changes.
 */
final readonly class GenerationRunRetriesExhaustedSubscriber
{
    public function __construct(
        private GenerationRunStore $store,
        private GenerationRunCompletion $completion,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        try {
            if ($message instanceof ProjectGeneratedHospital) {
                $error = $event->getThrowable();
                if ($this->store->failHospital($message->runId, $message->hospitalId, GenerationRunReason::RETRIES_EXHAUSTED, $error::class, $this->clock->now())) {
                    $this->logger->error('generation_run.hospital_failed', ['run' => $message->runId, 'hospital_id' => $message->hospitalId, 'reason' => GenerationRunReason::RETRIES_EXHAUSTED]);
                }
                $this->completion->check($message->runId);
            } elseif ($message instanceof FinishGenerationRun && $this->store->failRun($message->runId, GenerationRunReason::RETRIES_EXHAUSTED, $this->clock->now())) {
                $this->logger->error('generation_run.failed', ['run' => $message->runId, 'reason' => GenerationRunReason::RETRIES_EXHAUSTED]);
            }
        } catch (\Throwable $e) {
            // Never break the worker here; `app:generate:run:show` lists the run as stale.
            $this->logger->critical('generation_run.retries_exhausted_not_recorded', ['ex' => $e::class, 'msg' => $e->getMessage()]);
        }
    }
}
