<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use Psr\Clock\ClockInterface;

/**
 * Messenger backlog (ADR 019): degraded or unhealthy when the oldest message that is ready to be handled waits
 * longer than the configured limits. An empty queue is healthy; delayed messages and the failure queue do not count.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class MessengerBacklogHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'messenger_backlog';

    public function __construct(
        private MessengerHealthQuery $query,
        private MessengerHealthSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $now = $this->clock->now();
        $oldestQueue = null;
        $oldestMinutes = 0;
        foreach ($this->query->readyQueues($now) as $queue => $state) {
            $minutes = intdiv(max(0, $now->getTimestamp() - $state['oldestAt']->getTimestamp()), 60);
            if (null === $oldestQueue || $minutes > $oldestMinutes) {
                $oldestQueue = $queue;
                $oldestMinutes = $minutes;
            }
        }

        $status = match (true) {
            null === $oldestQueue || $oldestMinutes <= $this->settings->backlogWarningMinutes => HealthCheckStatus::Healthy,
            $oldestMinutes <= $this->settings->backlogCriticalMinutes => HealthCheckStatus::Degraded,
            default => HealthCheckStatus::Unhealthy,
        };

        if (null === $oldestQueue || HealthCheckStatus::Healthy === $status) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'ok');
        }

        return new HealthCheckResult(self::KEY, $status, \sprintf('oldest message waits %d min (%s)', $oldestMinutes, $oldestQueue));
    }
}
