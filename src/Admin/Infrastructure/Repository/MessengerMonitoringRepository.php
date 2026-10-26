<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Repository;

use App\Admin\Application\DTO\Messenger\MessengerCountsDto;
use App\Admin\Application\DTO\Messenger\MessengerFailureRowDto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads the Messenger counters (messenger_metric, messenger_failure; ADR 019) and the live queue state of the
 * Doctrine transport (messenger_messages, ADR 003). Doctrine stores the queue's times in UTC without a zone.
 */
final readonly class MessengerMonitoringRepository
{
    private const string COUNT_COLUMNS = <<<'SQL'
SUM(attempts) AS attempts, SUM(handled) AS handled, SUM(handled_after_retry) AS handled_after_retry,
SUM(retried) AS retried, SUM(failed) AS failed, SUM(duration_ms_sum) AS duration_ms_sum,
MAX(duration_ms_max) AS duration_ms_max, MAX(last_activity_at) AS last_activity_at
SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, MessengerCountsDto> transport => counters since the given time
     */
    public function countsByTransport(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT transport, '.self::COUNT_COLUMNS." FROM messenger_metric WHERE granularity = 'hour' AND bucket_start >= :since GROUP BY transport",
            ['since' => $this->utc($since)],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['transport']] = $this->counts($row);
        }

        return $counts;
    }

    /**
     * @return list<array{messageClass: string, transports: list<string>, counts: MessengerCountsDto}> most attempts first
     */
    public function countsByMessageClass(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT message_class, STRING_AGG(DISTINCT transport, \',\' ORDER BY transport) AS transports, '.self::COUNT_COLUMNS
            ." FROM messenger_metric WHERE granularity = 'hour' AND bucket_start >= :since GROUP BY message_class ORDER BY SUM(attempts) DESC, message_class",
            ['since' => $this->utc($since)],
        );

        return array_map(fn (array $row): array => [
            'messageClass' => (string) $row['message_class'],
            'transports' => explode(',', (string) $row['transports']),
            'counts' => $this->counts($row),
        ], $rows);
    }

    /**
     * Failed attempts grouped by message class and exception class, most recent first.
     *
     * @return list<MessengerFailureRowDto>
     */
    public function failures(\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT message_class, error_class, COUNT(*) AS attempts,
       SUM(CASE WHEN will_retry THEN 1 ELSE 0 END) AS retried, MAX(occurred_at) AS last_occurred_at
FROM messenger_failure
WHERE occurred_at >= :since
GROUP BY message_class, error_class
ORDER BY MAX(occurred_at) DESC, message_class, error_class
LIMIT :limit
SQL,
            ['since' => $this->utc($since), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): MessengerFailureRowDto => new MessengerFailureRowDto(
            (string) $row['message_class'],
            (string) $row['error_class'],
            (int) $row['attempts'],
            (int) $row['retried'],
            (int) $row['attempts'] - (int) $row['retried'],
            new \DateTimeImmutable((string) $row['last_occurred_at']),
        ), $rows);
    }

    /**
     * Live state per queue: pending (ready to be handled), waiting for a retry or delay, oldest ready message.
     *
     * @return array<string, array{pending: int, waiting: int, oldestPendingAt: \DateTimeImmutable|null}>
     */
    public function queues(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT queue_name,
       COUNT(*) FILTER (WHERE available_at <= :now) AS pending,
       COUNT(*) FILTER (WHERE available_at > :now) AS waiting,
       MIN(created_at) FILTER (WHERE available_at <= :now) AS oldest_pending_at
FROM messenger_messages
WHERE delivered_at IS NULL
GROUP BY queue_name
SQL,
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );

        $queues = [];
        foreach ($rows as $row) {
            $oldest = $row['oldest_pending_at'];
            $queues[(string) $row['queue_name']] = [
                'pending' => (int) $row['pending'],
                'waiting' => (int) $row['waiting'],
                'oldestPendingAt' => null === $oldest ? null : new \DateTimeImmutable((string) $oldest, new \DateTimeZone('UTC')),
            ];
        }

        return $queues;
    }

    /**
     * The last handling attempt of any message, from hourly and daily rows.
     */
    public function lastActivity(): ?\DateTimeImmutable
    {
        $last = $this->connection->fetchOne('SELECT MAX(last_activity_at) FROM messenger_metric');

        return null === $last || false === $last ? null : new \DateTimeImmutable((string) $last);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function counts(array $row): MessengerCountsDto
    {
        $lastActivity = $row['last_activity_at'] ?? null;

        return new MessengerCountsDto(
            (int) $row['attempts'],
            (int) $row['handled'],
            (int) $row['handled_after_retry'],
            (int) $row['retried'],
            (int) $row['failed'],
            (int) $row['duration_ms_sum'],
            (int) $row['duration_ms_max'],
            null === $lastActivity ? null : new \DateTimeImmutable((string) $lastActivity),
        );
    }

    private function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }
}
