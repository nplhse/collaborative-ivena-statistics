<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class UsageAnalyticsJourneysDto
{
    /**
     * @param list<array{routeName: string, sessionCount: int}>                     $entryRoutes
     * @param list<array{routeName: string, sessionCount: int}>                     $exitRoutes
     * @param list<array{fromRoute: string, toRoute: string, transitionCount: int}> $transitions
     */
    public function __construct(
        public array $entryRoutes,
        public array $exitRoutes,
        public array $transitions,
    ) {
    }
}
