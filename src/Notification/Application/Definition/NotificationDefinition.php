<?php

declare(strict_types=1);

namespace App\Notification\Application\Definition;

use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPriority;

/**
 * Declares a notification type and its delivery defaults on a subclass of {@see AppNotification} (ADR 016).
 *
 * Translation keys follow the type: "{type}.title" and "{type}.message" in the given translation domain.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class NotificationDefinition
{
    /**
     * @param list<NotificationChannel> $defaultChannels   channels used when the user has no override
     * @param list<NotificationChannel> $availableChannels channels the user may choose from
     * @param list<NotificationChannel> $mandatoryChannels channels used regardless of preferences
     * @param string                    $deduplicationTtl  ISO 8601 duration of the duplicate protection
     * @param string|null               $requiredRole      role a viewer needs for role-scoped types, e.g. "ROLE_ADMIN"
     *
     * @psalm-suppress PossiblyUnusedMethod Instantiated through reflection from the attribute.
     */
    public function __construct(
        public string $type,
        public NotificationCategory $category,
        public array $defaultChannels,
        public array $availableChannels,
        public NotificationPriority $priority = NotificationPriority::Normal,
        public bool $configurable = true,
        public array $mandatoryChannels = [],
        public NotificationAccessScope $accessScope = NotificationAccessScope::None,
        public string $deduplicationTtl = 'P7D',
        public string $translationDomain = 'notification',
        public ?string $requiredRole = null,
    ) {
    }

    public function titleKey(): string
    {
        return $this->type.'.title';
    }

    public function messageKey(): string
    {
        return $this->type.'.message';
    }

    public function deduplicationInterval(): \DateInterval
    {
        return new \DateInterval($this->deduplicationTtl);
    }
}
