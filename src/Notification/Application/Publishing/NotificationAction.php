<?php

declare(strict_types=1);

namespace App\Notification\Application\Publishing;

/**
 * Contextual link of a notification, stored as route name and parameters.
 * The URL is generated when the notification is rendered.
 */
final readonly class NotificationAction
{
    /**
     * @param array<string, scalar> $routeParameters
     *
     * @psalm-suppress PossiblyUnusedMethod Built by producing contexts from step 3b of ADR 016 on.
     */
    public function __construct(
        public string $route,
        public array $routeParameters = [],
    ) {
    }
}
