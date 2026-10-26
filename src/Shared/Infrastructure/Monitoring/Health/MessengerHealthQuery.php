<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Health;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads what the Messenger and scheduler health checks need (ADR 019): the Doctrine queues (messenger_messages,
 * times in UTC without a zone), the Messenger counters and the job run log.
 */
final readonly class MessengerHealthQuery
{
    public const string FAILED_QUEUE = 'failed';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Messages ready to be handled now, per queue (the failure queue and delayed messages are not ready).
     *
     * @return array<string, array{pending: int, oldestAt: \DateTimeImmutable}>
     */
    public function readyQueues(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT queue_name, COUNT(*) AS pending, MIN(created_at) AS oldest_at
FROM messenger_messages
WHERE delivered_at IS NULL AND queue_name <> :failed AND available_at <= :now
GROUP BY queue_name
SQL,
            ['failed' => self::FAILED_QUEUE, 'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );

        $queues = [];
        foreach ($rows as $row) {
            $queues[(string) $row['queue_name']] = [
                'pending' => (int) $row['pending'],
                'oldestAt' => new \DateTimeImmutable((string) $row['oldest_at'], new \DateTimeZone('UTC')),
            ];
        }

        return $queues;
    }

    /**
     * The last handling attempt a worker recorded, of any message.
     */
    public function lastActivity(): ?\DateTimeImmutable
    {
        $last = $this->connection->fetchOne('SELECT MAX(last_activity_at) FROM messenger_metric');

        return null === $last || false === $last ? null : new \DateTimeImmutable((string) $last);
    }

    /**
     * @return array{attempts: int, retried: int}
     */
    public function attemptsSince(\DateTimeImmutable $since): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COALESCE(SUM(attempts), 0) AS attempts, COALESCE(SUM(retried), 0) AS retried FROM messenger_metric WHERE granularity = 'hour' AND bucket_start >= :since",
            ['since' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP')],
        );

        return ['attempts' => \is_array($row) ? (int) $row['attempts'] : 0, 'retried' => \is_array($row) ? (int) $row['retried'] : 0];
    }

    /**
     * The latest finished run of each given job.
     *
     * @param list<string> $jobs
     *
     * @return array<string, array{status: string, finishedAt: \DateTimeImmutable}>
     */
    public function lastFinishedRuns(array $jobs): array
    {
        if ([] === $jobs) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (job) job, status, finished_at FROM job_run WHERE job IN (:jobs) AND finished_at IS NOT NULL ORDER BY job, finished_at DESC',
            ['jobs' => $jobs],
            ['jobs' => ArrayParameterType::STRING],
        );

        $runs = [];
        foreach ($rows as $row) {
            $runs[(string) $row['job']] = ['status' => (string) $row['status'], 'finishedAt' => new \DateTimeImmutable((string) $row['finished_at'])];
        }

        return $runs;
    }

    /**
     * Start of the run log: the oldest job_run row. Before the run log has existed for a job's interval, a job
     * without any run is not overdue yet (e.g. right after the deploy that introduced it).
     */
    public function runLogSince(): ?\DateTimeImmutable
    {
        $first = $this->connection->fetchOne('SELECT MIN(started_at) FROM job_run');

        return null === $first || false === $first ? null : new \DateTimeImmutable((string) $first);
    }
}
