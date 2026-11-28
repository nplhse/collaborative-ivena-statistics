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
 * Every finished day from the day after the last daily row (or the day of the first failure) up to yesterday is
 * written again, also with zeros, but never one older than the single failures are kept. Days are calendar days of
 * the app time zone (Europe/Berlin).
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
        $yesterday = $today->modify('-1 day');
        $oldestKept = $today->modify(\sprintf('-%d days', LoginFailureDaily::EVENT_RETENTION_DAYS));

        $lastAggregated = $this->query->findLastAggregatedDay();
        $first = $lastAggregated instanceof \DateTimeImmutable
            ? $lastAggregated->modify('+1 day')
            : ($this->query->findFirstEventDay() ?? $yesterday);
        $first = max($first, $oldestKept);
        // Yesterday is always written again, so a second run on the same day gives the same row.
        $first = min($first, $yesterday);

        $metrics = ['days_processed' => 0, 'failures' => 0, 'flagged_days' => 0];
        for ($day = $first; $day < $today; $day = $day->modify('+1 day')) {
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
