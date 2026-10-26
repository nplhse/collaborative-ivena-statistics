<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Messenger;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Counts handling attempts of Messenger messages per hour, message class and transport in messenger_metric,
 * and writes one messenger_failure row per failed attempt (ADR 019). Only class names, transport names and
 * counters are stored, never payloads or exception messages.
 *
 * Monitoring must not fail or retry a message, so every write catches its own errors and only logs them.
 */
final readonly class MessengerMetricRecorder
{
    public const string GRANULARITY_HOUR = 'hour';

    private const string UPSERT = <<<'SQL'
INSERT INTO messenger_metric (granularity, bucket_start, message_class, transport, attempts, handled, handled_after_retry, retried, failed, duration_ms_sum, duration_ms_max, last_activity_at)
VALUES (:granularity, :bucket, :class, :transport, 1, :handled, :handledAfterRetry, :retried, :failed, :duration, :duration, :now)
ON CONFLICT (granularity, bucket_start, message_class, transport) DO UPDATE SET
    attempts = messenger_metric.attempts + 1,
    handled = messenger_metric.handled + EXCLUDED.handled,
    handled_after_retry = messenger_metric.handled_after_retry + EXCLUDED.handled_after_retry,
    retried = messenger_metric.retried + EXCLUDED.retried,
    failed = messenger_metric.failed + EXCLUDED.failed,
    duration_ms_sum = messenger_metric.duration_ms_sum + EXCLUDED.duration_ms_sum,
    duration_ms_max = GREATEST(messenger_metric.duration_ms_max, EXCLUDED.duration_ms_max),
    last_activity_at = GREATEST(messenger_metric.last_activity_at, EXCLUDED.last_activity_at)
SQL;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A successful attempt; $afterRetry when an earlier attempt of the same message failed.
     */
    public function recordHandled(string $messageClass, string $transport, int $durationMs, bool $afterRetry): void
    {
        $this->write($messageClass, $transport, function () use ($messageClass, $transport, $durationMs, $afterRetry): void {
            $this->upsert($messageClass, $transport, $durationMs, handled: 1, handledAfterRetry: $afterRetry ? 1 : 0, retried: 0, failed: 0);
        });
    }

    /**
     * A failed attempt. With $willRetry Messenger tries again later; without it the message is given up
     * (moved to the failure transport or dropped), which ends it as failed.
     */
    public function recordFailed(string $messageClass, string $transport, int $durationMs, int $attempt, bool $willRetry, string $errorClass): void
    {
        $this->write($messageClass, $transport, function () use ($messageClass, $transport, $durationMs, $attempt, $willRetry, $errorClass): void {
            $this->connection->transactional(function () use ($messageClass, $transport, $durationMs, $attempt, $willRetry, $errorClass): void {
                $this->upsert($messageClass, $transport, $durationMs, handled: 0, handledAfterRetry: 0, retried: $willRetry ? 1 : 0, failed: $willRetry ? 0 : 1);
                $this->connection->insert('messenger_failure', [
                    'occurred_at' => $this->now(),
                    'message_class' => $this->truncate($messageClass),
                    'transport' => mb_substr($transport, 0, 64),
                    'attempt' => min($attempt, 32767),
                    'will_retry' => $willRetry,
                    'error_class' => $this->truncate($errorClass),
                ], ['will_retry' => 'boolean']);
            });
        });
    }

    private function upsert(string $messageClass, string $transport, int $durationMs, int $handled, int $handledAfterRetry, int $retried, int $failed): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $this->connection->executeStatement(self::UPSERT, [
            'granularity' => self::GRANULARITY_HOUR,
            'bucket' => $now->format('Y-m-d H:00:00+00:00'),
            'class' => $this->truncate($messageClass),
            'transport' => mb_substr($transport, 0, 64),
            'handled' => $handled,
            'handledAfterRetry' => $handledAfterRetry,
            'retried' => $retried,
            'failed' => $failed,
            'duration' => max(0, $durationMs),
            'now' => $now->format('Y-m-d H:i:sP'),
        ]);
    }

    private function write(string $messageClass, string $transport, \Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $exception) {
            $this->logger->warning('messenger_metric.record_failed', [
                'message_class' => $messageClass,
                'transport' => $transport,
                'error_class' => $exception::class,
            ]);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:sP');
    }

    private function truncate(string $value): string
    {
        return mb_substr($value, 0, 255);
    }
}
