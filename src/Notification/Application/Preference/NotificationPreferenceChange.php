<?php

declare(strict_types=1);

namespace App\Notification\Application\Preference;

use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;

/**
 * The wanted state of one override: on, off, or null to fall back to the default.
 */
final readonly class NotificationPreferenceChange
{
    /**
     * @param string $key category value or type identifier, depending on the scope
     */
    public function __construct(
        public NotificationPreferenceScope $scope,
        public string $key,
        public NotificationChannel $channel,
        public ?bool $enabled,
    ) {
    }
}
