<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Messenger;

use App\Shared\Application\JobRun\CurrentJobRunInterface;
use App\Shared\Application\Monitoring\Message\SampleMessengerQueuesMessage;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores one sample per hour of the Doctrine queues (messenger_messages, ADR 003): messages ready to be handled,
 * waiting for a retry or delay, in the failure queue, and the age of the oldest ready one (ADR 019). Running it
 * again in the same hour replaces that hour's sample. Doctrine stores the queue's times in UTC without a zone.
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class SampleMessengerQueuesMessageHandler
{
    public const string FAILED_QUEUE = 'failed';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(SampleMessengerQueuesMessage $message): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $state = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT COUNT(*) FILTER (WHERE queue_name <> :failed AND available_at <= :now) AS pending,
       COUNT(*) FILTER (WHERE queue_name <> :failed AND available_at > :now) AS waiting,
       COUNT(*) FILTER (WHERE queue_name = :failed) AS failed,
       MIN(created_at) FILTER (WHERE queue_name <> :failed AND available_at <= :now) AS oldest_pending_at
FROM messenger_messages
WHERE delivered_at IS NULL
SQL,
            ['failed' => self::FAILED_QUEUE, 'now' => $now->format('Y-m-d H:i:s')],
        );

        $oldest = \is_array($state) ? $state['oldest_pending_at'] : null;
        $oldestAge = null === $oldest ? null : max(0, $now->getTimestamp() - new \DateTimeImmutable((string) $oldest, new \DateTimeZone('UTC'))->getTimestamp());
        $sample = [
            'pending' => \is_array($state) ? (int) $state['pending'] : 0,
            'waiting' => \is_array($state) ? (int) $state['waiting'] : 0,
            'failed' => \is_array($state) ? (int) $state['failed'] : 0,
        ];

        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO messenger_queue_sample (sampled_at, pending, waiting, failed, oldest_pending_age_seconds)
VALUES (:sampledAt, :pending, :waiting, :failed, :oldestAge)
ON CONFLICT (sampled_at) DO UPDATE SET
    pending = EXCLUDED.pending, waiting = EXCLUDED.waiting, failed = EXCLUDED.failed,
    oldest_pending_age_seconds = EXCLUDED.oldest_pending_age_seconds
SQL,
            ['sampledAt' => $now->format('Y-m-d H:00:00+00:00'), 'oldestAge' => $oldestAge] + $sample,
        );

        $this->jobRun->addMetrics($sample + ['oldest_pending_age_seconds' => $oldestAge]);
    }
}
