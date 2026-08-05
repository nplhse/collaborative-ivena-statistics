<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

final readonly class UsageAnalyticsAdoptionDto
{
    /**
     * @param list<array{eventName: string, eventCount: int, uniqueUsers: int}>     $topEvents
     * @param list<array{eventName: string, userRole: string, eventCount: int}>     $eventsByRole
     * @param list<array{userRole: string, featureArea: string, requestCount: int}> $roleAreaMatrix
     * @param list<array{level: int, label: string, userCount: int}>                $engagementDepth
     */
    public function __construct(
        public array $topEvents,
        public array $eventsByRole,
        public array $roleAreaMatrix,
        public array $engagementDepth,
    ) {
    }
}
