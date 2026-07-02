<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Repository;

use App\Admin\Application\DTO\MessengerQueueStatDto;
use App\Admin\Application\DTO\MessengerStatsDto;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class MessengerStatsRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function getStats(): MessengerStatsDto
    {
        /** @var list<array{queue_name: string, pending_count: int|string, oldest_created_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT queue_name, COUNT(*) AS pending_count, MIN(created_at) AS oldest_created_at
             FROM messenger_messages
             WHERE delivered_at IS NULL
             GROUP BY queue_name
             ORDER BY queue_name ASC',
        );

        $queues = [];
        $failedCount = 0;

        foreach ($rows as $row) {
            $queueName = $row['queue_name'];
            $pendingCount = (int) $row['pending_count'];
            $oldest = isset($row['oldest_created_at'])
                ? new \DateTimeImmutable($row['oldest_created_at'])
                : null;

            $queues[] = new MessengerQueueStatDto($queueName, $pendingCount, $oldest);

            if ('failed' === $queueName) {
                $failedCount = $pendingCount;
            }
        }

        return new MessengerStatsDto($queues, $failedCount);
    }

    /**
     * @return list<array{id: int, queue_name: string, created_at: string, message_class: string, error_preview: string}>
     */
    public function findFailedMessages(int $limit = 50, int $offset = 0): array
    {
        /** @var list<array{id: int|string, queue_name: string, created_at: string, body: string, headers: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, queue_name, created_at, body, headers
             FROM messenger_messages
             WHERE queue_name = :queue
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset',
            ['queue' => 'failed', 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $messages = [];
        foreach ($rows as $row) {
            $headers = json_decode($row['headers'], true);
            $messageClass = '';
            if (\is_array($headers) && isset($headers['type']) && \is_string($headers['type'])) {
                $messageClass = $headers['type'];
            }

            $messages[] = [
                'id' => (int) $row['id'],
                'queue_name' => $row['queue_name'],
                'created_at' => $row['created_at'],
                'message_class' => $messageClass,
                'error_preview' => $this->buildErrorPreview($row['body']),
            ];
        }

        return $messages;
    }

    public function countFailedMessages(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'failed'],
        );
    }

    /**
     * @return array{id: int, queue_name: string, created_at: string, body: string, headers: string, message_class: string}|null
     */
    public function findFailedMessageById(int $id): ?array
    {
        /** @var array{id: int|string, queue_name: string, created_at: string, body: string, headers: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, queue_name, created_at, body, headers
             FROM messenger_messages
             WHERE id = :id AND queue_name = :queue',
            ['id' => $id, 'queue' => 'failed'],
        );

        if (false === $row) {
            return null;
        }

        $headers = json_decode($row['headers'], true);
        $messageClass = '';
        if (\is_array($headers) && isset($headers['type']) && \is_string($headers['type'])) {
            $messageClass = $headers['type'];
        }

        return [
            'id' => (int) $row['id'],
            'queue_name' => $row['queue_name'],
            'created_at' => $row['created_at'],
            'body' => $row['body'],
            'headers' => $row['headers'],
            'message_class' => $messageClass,
        ];
    }

    public function deleteFailedMessageById(int $id): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM messenger_messages WHERE id = :id AND queue_name = :queue',
            ['id' => $id, 'queue' => 'failed'],
        ) > 0;
    }

    /**
     * @param list<int> $ids
     */
    public function deleteFailedMessagesByIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        /** @psalm-suppress InvalidArgument Doctrine DBAL array parameter types */
        return $this->connection->executeStatement(
            'DELETE FROM messenger_messages WHERE queue_name = :queue AND id IN (:ids)',
            ['queue' => 'failed', 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    public function deleteAllFailedMessages(): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'failed'],
        );
    }

    private function buildErrorPreview(string $body): string
    {
        $preview = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if (strlen($preview) > 160) {
            return substr($preview, 0, 157).'...';
        }

        return $preview;
    }
}
