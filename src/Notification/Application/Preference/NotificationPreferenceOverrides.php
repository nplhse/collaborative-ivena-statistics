<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Domain\Enum\NotificationChannel;

/**
 * A user's stored overrides for one notification type: per channel, on or off.
 * A channel without an entry falls back to the next level (type → category → default).
 */
final readonly class NotificationPreferenceOverrides
{
    /**
     * @param array<string, bool> $typeOverrides     keyed by channel value
     * @param array<string, bool> $categoryOverrides keyed by channel value
     */
    public function __construct(
        private array $typeOverrides = [],
        private array $categoryOverrides = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function forType(NotificationChannel $channel): ?bool
    {
        return $this->typeOverrides[$channel->value] ?? null;
    }

    public function forCategory(NotificationChannel $channel): ?bool
    {
        return $this->categoryOverrides[$channel->value] ?? null;
    }
}
