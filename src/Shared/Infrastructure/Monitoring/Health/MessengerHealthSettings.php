<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Limits of the Messenger health checks from app.messenger_monitoring.health (ADR 019).
 */
final readonly class MessengerHealthSettings
{
    public int $backlogWarningMinutes;
    public int $backlogCriticalMinutes;
    public int $stalledAfterMinutes;
    public int $retryRateWarningPercent;
    public int $retryMinAttempts;

    /**
     * @param array{backlog_warning_minutes: int, backlog_critical_minutes: int, stalled_after_minutes: int, retry_rate_warning_percent: int, retry_min_attempts: int} $health
     *
     * @psalm-suppress PossiblyUnusedMethod Wired by the container.
     */
    public function __construct(
        #[Autowire(param: 'app.messenger_monitoring.health')]
        array $health,
    ) {
        $this->backlogWarningMinutes = $health['backlog_warning_minutes'];
        $this->backlogCriticalMinutes = $health['backlog_critical_minutes'];
        $this->stalledAfterMinutes = $health['stalled_after_minutes'];
        $this->retryRateWarningPercent = $health['retry_rate_warning_percent'];
        $this->retryMinAttempts = $health['retry_min_attempts'];
    }
}
