<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use Psr\Clock\ClockInterface;

/**
 * Stalled processing (ADR 019): unhealthy when messages have been ready for longer than the limit and no worker
 * recorded any handling attempt for as long. This catches a stopped worker, which leaves no failed message behind.
 * An empty queue or an idle worker is never a failure.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class MessengerStalledHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'messenger_stalled';

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
        $limit = $now->modify(\sprintf('-%d minutes', $this->settings->stalledAfterMinutes));

        $waiting = 0;
        foreach ($this->query->readyQueues($now) as $state) {
            if ($state['oldestAt'] < $limit) {
                $waiting += $state['pending'];
            }
        }

        $lastActivity = $this->query->lastActivity();
        if (0 === $waiting || ($lastActivity instanceof \DateTimeImmutable && $lastActivity >= $limit)) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'ok');
        }

        $value = $lastActivity instanceof \DateTimeImmutable
            ? \sprintf('%d message(s) waiting, no handling for %d min', $waiting, intdiv(max(0, $now->getTimestamp() - $lastActivity->getTimestamp()), 60))
            : \sprintf('%d message(s) waiting, no handling recorded', $waiting);

        return new HealthCheckResult(self::KEY, HealthCheckStatus::Unhealthy, $value);
    }
}
