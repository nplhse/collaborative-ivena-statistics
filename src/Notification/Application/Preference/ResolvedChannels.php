<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Domain\Enum\NotificationChannel;

final readonly class ResolvedChannels
{
    /**
     * @param list<NotificationChannel> $channels
     */
    public function __construct(
        public array $channels,
    ) {
    }

    public function has(NotificationChannel $channel): bool
    {
        return \in_array($channel, $this->channels, true);
    }
}
