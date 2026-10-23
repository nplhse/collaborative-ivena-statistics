<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification;

use App\User\Domain\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Stores preference overrides directly, e.g. a user who switched off a reminder.
 */
final readonly class NotificationPreferenceTestState
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function switchOff(User $user, string $type, string $channel = 'email'): void
    {
        $this->connection->executeStatement(
            "INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled) VALUES (?, 'type', ?, ?, false)",
            [$user->getId(), $type, $channel],
        );
    }
}
