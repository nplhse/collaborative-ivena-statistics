<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Resolves the effective channels for one user and type (ADR 016, "Preferences and resolution").
 */
final class NotificationChannelResolver
{
    public function resolve(NotificationDefinition $definition, NotificationPreferenceOverrides $overrides): ResolvedChannels
    {
        $channels = [];

        foreach ($definition->availableChannels as $channel) {
            if ($this->isEnabled($definition, $overrides, $channel)) {
                $channels[] = $channel;
            }
        }

        if (!\in_array(NotificationChannel::InApp, $channels, true)) {
            $channels = array_values(array_filter(
                $channels,
                static fn (NotificationChannel $channel): bool => NotificationChannel::Toast !== $channel,
            ));
        }

        return new ResolvedChannels($channels);
    }

    private function isEnabled(
        NotificationDefinition $definition,
        NotificationPreferenceOverrides $overrides,
        NotificationChannel $channel,
    ): bool {
        if (\in_array($channel, $definition->mandatoryChannels, true)) {
            return true;
        }

        $default = \in_array($channel, $definition->defaultChannels, true);

        if (!$definition->configurable) {
            return $default;
        }

        return $overrides->forType($channel) ?? $overrides->forCategory($channel) ?? $default;
    }
}
