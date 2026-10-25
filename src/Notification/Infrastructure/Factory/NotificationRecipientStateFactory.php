<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Factory;

use App\Notification\Domain\Entity\NotificationRecipientState;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<NotificationRecipientState>
 */
final class NotificationRecipientStateFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return NotificationRecipientState::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'user' => UserFactory::new(),
            'updatedAt' => new \DateTimeImmutable(),
            'version' => 1,
            'unreadCount' => 0,
        ];
    }
}
