<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Factory;

use App\Notification\Domain\Entity\NotificationRecipientState;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Clock\Clock;
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
            'updatedAt' => Clock::get()->now(),
            'version' => 1,
            'unreadCount' => 0,
        ];
    }
}
