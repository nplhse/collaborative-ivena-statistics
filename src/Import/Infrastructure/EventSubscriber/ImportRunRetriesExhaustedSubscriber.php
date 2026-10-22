<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Message\ImportRunMessageInterface;
use App\Import\Application\Service\ImportRunLifecycle;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Ends a run as failed (reason code retries_exhausted) once Messenger gives up on its message.
 * Retryable errors leave the run open until then.
 */
final readonly class ImportRunRetriesExhaustedSubscriber
{
    public function __construct(
        private ImportRunLifecycle $runLifecycle,
        private LoggerInterface $importLogger,
    ) {
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ImportRunMessageInterface || null === $message->getRunId()) {
            return;
        }

        try {
            $this->runLifecycle->failAfterRetriesExhausted($message);
        } catch (\Throwable $e) {
            // Never break the worker here. The run stays open and the log names it.
            $this->importLogger->critical('import.run.retries_exhausted_not_recorded', [
                'id' => $message->getImportId(),
                'run_id' => $message->getRunId(),
                'ex' => $e::class,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
