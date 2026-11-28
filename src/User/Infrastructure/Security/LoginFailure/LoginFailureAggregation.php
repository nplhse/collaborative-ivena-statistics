<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security\LoginFailure;

use App\User\Domain\Entity\LoginFailureDaily;
use App\User\Infrastructure\Query\LoginFailureDayQuery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Daily job `login_failure_aggregation` (#1487): folds the single failures of finished days into LoginFailureDaily,
 * then deletes single failures and daily rows past their retention.
 *
 * Which days are written is decided by LoginFailureDaily::daysToFold(). Days are calendar days of the app time zone
 * (Europe/Berlin).
 */
final readonly class LoginFailureAggregation
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private LoginFailureDayQuery $query,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{days_processed: int, failures: int, flagged_days: int, events_deleted: int, daily_rows_deleted: int}
     */
    public function run(): array
    {
        $today = $this->startOfToday();
        $oldestKept = $today->modify(\sprintf('-%d days', LoginFailureDaily::EVENT_RETENTION_DAYS));

        $metrics = ['days_processed' => 0, 'failures' => 0, 'flagged_days' => 0];
        foreach (LoginFailureDaily::daysToFold($this->query->findLastAggregatedDay(), $this->query->findFirstEventDay(), $today) as $day) {
            $daily = $this->aggregate($day);
            ++$metrics['days_processed'];
            $metrics['failures'] += $daily->getFailures();
            $metrics['flagged_days'] += $daily->isFlagged() ? 1 : 0;
        }

        return $metrics + [
            'events_deleted' => $this->query->deleteEventsBefore($oldestKept),
            'daily_rows_deleted' => $this->query->deleteDailyBefore(
                $today->modify(\sprintf('-%d days', LoginFailureDaily::DAILY_RETENTION_DAYS)),
            ),
        ];
    }

    private function aggregate(\DateTimeImmutable $day): LoginFailureDaily
    {
        $counts = $this->query->countDay($day, $day->modify('+1 day'));

        return $this->entityManager->wrapInTransaction(function () use ($day, $counts): LoginFailureDaily {
            $this->query->deleteDaily($day);
            $daily = new LoginFailureDaily(
                date: $day,
                failures: $counts['failures'],
                refused: $counts['refused'],
                lockThresholdsReached: $counts['lock_thresholds'],
                accountsLocked: $counts['locks'],
                distinctIdentifiers: $counts['distinct_identifiers'],
                distinctIps: $counts['distinct_ips'],
                maxIpsPerIdentifier: $counts['max_ips_per_identifier'],
                maxIdentifiersPerIp: $counts['max_identifiers_per_ip'],
            );
            $this->entityManager->persist($daily);
            $this->entityManager->flush();
            $this->entityManager->detach($daily);

            return $daily;
        });
    }

    private function startOfToday(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    }
}
