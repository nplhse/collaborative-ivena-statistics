<?php

declare(strict_types=1);

namespace App\Analytics\Application\UsageEvents;

use App\Analytics\Domain\Enum\FeatureArea;
use App\User\Domain\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Public entry point for usage events. Consent-gated: no row without analytics consent.
 */
final readonly class UsageAnalytics
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private UsageEventContextResolverInterface $contextResolver,
        private UsageEventRecorderInterface $recorder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|null> $context Non-PII only
     */
    public function record(string $eventName, ?FeatureArea $featureArea = null, array $context = []): void
    {
        try {
            $resolved = $this->contextResolver->resolveFromRequest();
            if (!$resolved->allowed) {
                return;
            }

            $this->persist($eventName, $featureArea, $resolved, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record usage analytics event.', [
                'event' => $eventName,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Worker / no-request contexts. Persists only when the user has analytics consent on file.
     *
     * @param array<string, scalar|null> $context Non-PII only
     */
    public function recordForUser(
        string $eventName,
        User $user,
        ?FeatureArea $featureArea = null,
        array $context = [],
    ): void {
        try {
            $resolved = $this->contextResolver->resolveForUser($user);
            if (!$resolved->allowed) {
                return;
            }

            $this->persist($eventName, $featureArea, $resolved, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record usage analytics event for user.', [
                'event' => $eventName,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function persist(
        string $eventName,
        ?FeatureArea $featureArea,
        UsageEventContext $resolved,
        array $context,
    ): void {
        if (null !== $resolved->userRole && !\array_key_exists('user_role', $context)) {
            $context['user_role'] = $resolved->userRole;
        }

        $this->recorder->record(
            $eventName,
            $featureArea,
            $resolved->analyticsUserKey,
            $resolved->visitorKey,
            $resolved->sessionKey,
            $context,
        );
    }
}
