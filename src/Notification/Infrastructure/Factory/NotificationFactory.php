<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Factory;

use App\Notification\Domain\Entity\Notification;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationPriority;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Inbox entries for tests and fixtures. Production code writes entries through the publisher.
 *
 * @extends PersistentObjectFactory<Notification>
 */
final class NotificationFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Notification::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'recipient' => UserFactory::new(),
            'type' => 'test.example',
            'category' => NotificationCategory::Announcements,
            'priority' => NotificationPriority::Normal,
            'translationDomain' => 'notification',
            'titleKey' => 'test.example.title',
            'messageKey' => 'test.example.message',
            'accessScope' => NotificationAccessScope::None,
            'inAppVisible' => true,
            'createdAt' => Clock::get()->now(),
        ];
    }
}
