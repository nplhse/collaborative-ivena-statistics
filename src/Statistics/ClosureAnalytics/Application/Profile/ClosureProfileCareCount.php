<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Profile;

final readonly class ClosureProfileCareCount
{
    public function __construct(
        public string $careLevel,
        public int $eventCount,
    ) {
    }
}
