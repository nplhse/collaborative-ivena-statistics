<?php

declare(strict_types=1);

namespace App\Analytics\Application\UsageEvents;

use App\Analytics\Domain\Entity\AnalyticsProductEvent;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;

/**
 * Persists usage events. Prefer {@see UsageAnalytics} as the public entry point.
 *
 * @psalm-suppress UnusedClass Wired via services.yaml alias for UsageEventRecorderInterface.
 */
final readonly class UsageEventRecorder implements UsageEventRecorderInterface
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsProductEventRepository $repository,
    ) {
    }

    /**
     * @param array<string, scalar|null> $context Non-PII context only
     */
    #[\Override]
    public function record(
        string $eventName,
        ?FeatureArea $featureArea = null,
        ?string $analyticsUserKey = null,
        ?string $visitorKey = null,
        ?string $sessionKey = null,
        array $context = [],
    ): void {
        $this->repository->save(new AnalyticsProductEvent(
            $eventName,
            $featureArea,
            $analyticsUserKey,
            $visitorKey,
            $sessionKey,
            $context,
        ));
    }
}
