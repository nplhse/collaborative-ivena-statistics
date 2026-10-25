<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Application\Contract\NotificationPreferenceStoreInterface;
use App\Notification\Application\Definition\NotificationRegistry;

/**
 * The channels one recipient would get for a type right now: definition, stored overrides and
 * mandatory channels, resolved like the publisher does.
 */
final readonly class RecipientChannelResolver
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationRegistry $registry,
        private NotificationPreferenceStoreInterface $preferences,
        private NotificationChannelResolver $channelResolver,
    ) {
    }

    public function resolve(int $userId, string $type): ResolvedChannels
    {
        $definition = $this->registry->get($type);

        return $this->channelResolver->resolve($definition, $this->preferences->overridesFor($userId, $definition));
    }
}
