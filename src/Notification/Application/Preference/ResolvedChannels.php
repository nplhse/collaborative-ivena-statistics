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

    /**
     * Channels delivered outside the application (everything except in-app and toast).
     *
     * @return list<NotificationChannel>
     */
    public function external(): array
    {
        return array_values(array_filter(
            $this->channels,
            static fn (NotificationChannel $channel): bool => NotificationChannel::InApp !== $channel && NotificationChannel::Toast !== $channel,
        ));
    }
}
