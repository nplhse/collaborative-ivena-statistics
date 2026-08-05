<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class UsageAnalyticsJourneysDto
{
    /**
     * @param list<array{step: string, uniqueUsers: int, conversionFromPreviousPercent: float|null}> $onboardingFunnel
     * @param list<array{routeName: string, sessionCount: int}>                                      $entryRoutes
     * @param list<array{routeName: string, sessionCount: int}>                                      $exitRoutes
     * @param list<array{fromRoute: string, toRoute: string, transitionCount: int}>                  $transitions
     * @param list<array{metric: string, medianDays: float|null, sampleSize: int}>                   $timeToFirst
     */
    public function __construct(
        public array $onboardingFunnel,
        public array $entryRoutes,
        public array $exitRoutes,
        public array $transitions,
        public array $timeToFirst,
    ) {
    }
}
