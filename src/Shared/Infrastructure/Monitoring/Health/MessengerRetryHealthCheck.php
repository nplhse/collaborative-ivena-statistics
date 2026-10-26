<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\Health;

use App\Shared\Application\Health\HealthCheckContributorInterface;
use App\Shared\Application\Health\HealthCheckResult;
use App\Shared\Application\Health\HealthCheckStatus;
use Psr\Clock\ClockInterface;

/**
 * Retry rate of the last 24 hours (ADR 019): degraded when more attempts than the limit failed and were retried.
 * Judged only with enough attempts, so a single retry on a quiet day does not alarm.
 *
 * @psalm-suppress UnusedClass Tagged via HealthCheckContributorInterface.
 */
final readonly class MessengerRetryHealthCheck implements HealthCheckContributorInterface
{
    public const string KEY = 'messenger_retries';

    public function __construct(
        private MessengerHealthQuery $query,
        private MessengerHealthSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function check(): HealthCheckResult
    {
        $counts = $this->query->attemptsSince($this->clock->now()->modify('-24 hours'));
        if ($counts['attempts'] < $this->settings->retryMinAttempts) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'ok');
        }

        $rate = $counts['retried'] * 100 / $counts['attempts'];
        if ($rate <= $this->settings->retryRateWarningPercent) {
            return new HealthCheckResult(self::KEY, HealthCheckStatus::Healthy, 'ok');
        }

        return new HealthCheckResult(
            self::KEY,
            HealthCheckStatus::Degraded,
            \sprintf('retry rate %.1f %% (%d of %d attempts in 24 h)', $rate, $counts['retried'], $counts['attempts']),
        );
    }
}
