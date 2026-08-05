<?php

declare(strict_types=1);

namespace App\Analytics\Application\UsageEvents;

use App\Analytics\Domain\Enum\FeatureArea;

interface UsageEventRecorderInterface
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function record(
        string $eventName,
        ?FeatureArea $featureArea = null,
        ?string $analyticsUserKey = null,
        ?string $visitorKey = null,
        ?string $sessionKey = null,
        array $context = [],
    ): void;
}
