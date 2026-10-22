<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification;

use App\Notification\Domain\Entity\Notification;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Sets read and removal timestamps directly, because the entity is read-only and has no setters.
 */
final readonly class NotificationTestState
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function markRead(Notification $notification, \DateTimeImmutable $at): void
    {
        $this->set($notification, 'read_at', $at);
    }

    public function markRemoved(Notification $notification, \DateTimeImmutable $at): void
    {
        $this->set($notification, 'removed_at', $at);
    }

    private function set(Notification $notification, string $column, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            \sprintf('UPDATE notification SET %s = :at WHERE id = :id', $column),
            ['at' => $at, 'id' => $notification->getId()],
            ['at' => Types::DATETIME_IMMUTABLE],
        );
    }
}
