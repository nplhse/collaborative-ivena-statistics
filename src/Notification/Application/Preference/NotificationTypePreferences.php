<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Application\Definition\NotificationDefinition;

/**
 * @psalm-suppress PossiblyUnusedProperty Read by the settings template.
 */
final readonly class NotificationTypePreferences
{
    /**
     * @param array<string, NotificationPreferenceControl> $controls keyed by channel value
     */
    public function __construct(
        public NotificationDefinition $definition,
        public array $controls,
    ) {
    }
}
