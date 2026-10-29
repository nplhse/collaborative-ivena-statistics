<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Query;

use App\DataFixtures\Generator\Application\Run\WorkerState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Reads the Doctrine Messenger queues the background stage of a generation run uses (`high` for imports, `low` for
 * projections and the final stage). Read-only; without the table (another transport) the state is unknown.
 */
final readonly class MessengerQueueQuery
{
    /** @var list<string> */
    public const array QUEUES = ['high', 'low'];

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function workerState(): WorkerState
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
            return WorkerState::unknown();
        }

        // The Doctrine transport writes its times in UTC.
        $utc = new \DateTimeZone('UTC');
        $nowTime = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($utc);
        $now = $nowTime->format('Y-m-d H:i:s');
        /** @var array{waiting: int|string, in_progress: int|string, oldest: string|null} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE delivered_at IS NULL AND available_at <= :now) AS waiting,
                    COUNT(*) FILTER (WHERE delivered_at IS NOT NULL) AS in_progress,
                    MIN(available_at) FILTER (WHERE delivered_at IS NULL AND available_at <= :now) AS oldest
             FROM messenger_messages WHERE queue_name IN (:queues)',
            ['now' => $now, 'queues' => self::QUEUES],
            ['queues' => ArrayParameterType::STRING],
        );

        $oldest = null === $row['oldest'] ? 0 : max(0, $nowTime->getTimestamp() - new \DateTimeImmutable($row['oldest'], $utc)->getTimestamp());

        return new WorkerState(known: true, waiting: (int) $row['waiting'], inProgress: (int) $row['in_progress'], oldestWaitingSeconds: $oldest);
    }
}
