<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Records a job_run row for every worker handling of a message that a context registered via
 * RecordedJobsInterface (ADR 019): running at the start, then succeeded, skipped or failed with duration,
 * peak memory, exceeded thresholds and the counters the handler reported through CurrentJobRun.
 *
 * Only messages received by a worker are recorded (scheduler included): dispatching a message that is sent to
 * a transport handles nothing here, and synchronous handling (`sync://`) happens in the dispatching process.
 * Jobs with their own telemetry (ADR 018) are not registered.
 *
 * @psalm-suppress UnusedClass Registered as bus middleware in config/packages/messenger.yaml.
 */
final class JobRunMiddleware implements MiddlewareInterface
{
    public const string STATUS_SUCCEEDED = 'succeeded';
    public const string STATUS_SKIPPED = 'skipped';
    public const string STATUS_FAILED = 'failed';

    /** @var array<class-string, string>|null */
    private ?array $jobs = null;

    /**
     * @param iterable<RecordedJobsInterface> $recordedJobs
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private readonly JobRunRecorder $recorder,
        private readonly JobRunThresholdConfig $thresholds,
        private readonly CurrentJobRun $currentRun,
        private readonly ClockInterface $clock,
        #[AutowireIterator(RecordedJobsInterface::TAG)]
        private readonly iterable $recordedJobs = [],
    ) {
    }

    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $job = $this->jobs()[$envelope->getMessage()::class] ?? null;
        if (null === $job || !$this->receivedByWorker($envelope) || $this->currentRun->isActive()) {
            return $stack->next()->handle($envelope, $stack);
        }

        $runId = Uuid::v7()->toRfc4122();
        $trigger = $envelope->last(ScheduledStamp::class) instanceof ScheduledStamp ? 'scheduler' : 'messenger';
        $this->recorder->start($runId, $job, $trigger, attempt: RedeliveryStamp::getRetryCountFromEnvelope($envelope) + 1);
        $this->currentRun->begin();
        memory_reset_peak_usage();
        $startedAt = $this->clock->now();

        try {
            $handled = $stack->next()->handle($envelope, $stack);
            $status = null === $this->currentRun->skipReason() ? self::STATUS_SUCCEEDED : self::STATUS_SKIPPED;
            $this->finish($runId, $job, $status, $startedAt);

            return $handled;
        } catch (\Throwable $exception) {
            $this->finish($runId, $job, self::STATUS_FAILED, $startedAt, $this->cause($exception));

            throw $exception;
        } finally {
            $this->currentRun->reset();
        }
    }

    private function finish(string $runId, string $job, string $status, \DateTimeImmutable $startedAt, ?\Throwable $error = null): void
    {
        $seconds = (float) $this->clock->now()->format('U.u') - (float) $startedAt->format('U.u');
        $seconds = max(0.0, $seconds);
        $peakMemory = memory_get_peak_usage(true);

        $this->recorder->finish(
            $runId,
            $job,
            $status,
            (int) round($seconds * 1000.0),
            $peakMemory,
            $this->thresholds->for($job)->exceeded($seconds, $peakMemory),
            $this->currentRun->metrics(),
            $error,
        );
    }

    /**
     * A worker received the message from a transport. The sync transport also adds a ReceivedStamp, but its
     * envelope still carries the SentStamp, which is dropped when a message is stored in a queue.
     */
    private function receivedByWorker(Envelope $envelope): bool
    {
        return $envelope->last(ReceivedStamp::class) instanceof ReceivedStamp
            && !$envelope->last(SentStamp::class) instanceof SentStamp;
    }

    /**
     * The handler's exception rather than Messenger's wrapper.
     */
    private function cause(\Throwable $exception): \Throwable
    {
        if ($exception instanceof HandlerFailedException) {
            foreach ($exception->getWrappedExceptions() as $wrapped) {
                return $wrapped;
            }
        }

        return $exception;
    }

    /**
     * @return array<class-string, string>
     */
    private function jobs(): array
    {
        if (null === $this->jobs) {
            $this->jobs = [];
            foreach ($this->recordedJobs as $recordedJobs) {
                $this->jobs += $recordedJobs->recordedJobs();
            }
        }

        return $this->jobs;
    }
}
