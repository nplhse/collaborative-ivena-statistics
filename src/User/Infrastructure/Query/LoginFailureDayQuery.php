<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Query;

use App\User\Domain\Enum\LoginFailureKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads `login_failure_event` for the daily counters (#1487). Bounds are local times of the app time zone, the same
 * clock time Doctrine writes into `occurred_at`.
 */
final readonly class LoginFailureDayQuery
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{failures: int, refused: int, lock_thresholds: int, locks: int, distinct_identifiers: int, distinct_ips: int, max_ips_per_identifier: int, max_identifiers_per_ip: int}
     */
    public function countDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'failure' => LoginFailureKind::Failure->value,
            'refused' => LoginFailureKind::Refused->value,
            'lock_threshold' => LoginFailureKind::LockThresholdReached->value,
            'account_locked' => LoginFailureKind::AccountLocked->value,
            'attempts' => [LoginFailureKind::Failure->value, LoginFailureKind::Refused->value],
        ];
        $types = ['attempts' => ArrayParameterType::STRING];

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) FILTER (WHERE kind = :failure) AS failures,
                       COUNT(*) FILTER (WHERE kind = :refused) AS refused,
                       COUNT(*) FILTER (WHERE kind = :lock_threshold) AS lock_thresholds,
                       COUNT(*) FILTER (WHERE kind = :account_locked) AS locks,
                       COUNT(DISTINCT identifier_hash) FILTER (WHERE kind IN (:attempts)) AS distinct_identifiers,
                       COUNT(DISTINCT ip_hash) FILTER (WHERE kind IN (:attempts)) AS distinct_ips
                FROM login_failure_event
                WHERE occurred_at >= :from AND occurred_at < :to
                SQL,
            $params,
            $types,
        );

        $attemptParams = array_intersect_key($params, array_flip(['from', 'to', 'attempts']));

        return [
            'failures' => $this->toInt($row['failures'] ?? 0),
            'refused' => $this->toInt($row['refused'] ?? 0),
            'lock_thresholds' => $this->toInt($row['lock_thresholds'] ?? 0),
            'locks' => $this->toInt($row['locks'] ?? 0),
            'distinct_identifiers' => $this->toInt($row['distinct_identifiers'] ?? 0),
            'distinct_ips' => $this->toInt($row['distinct_ips'] ?? 0),
            'max_ips_per_identifier' => $this->maxDistinct('ip_hash', 'identifier_hash', $attemptParams, $types),
            'max_identifiers_per_ip' => $this->maxDistinct('identifier_hash', 'ip_hash', $attemptParams, $types),
        ];
    }

    /** Local start of the day with the oldest event, or null without events. */
    public function findFirstEventDay(): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT MIN(occurred_at) FROM login_failure_event');

        return \is_string($value) ? new \DateTimeImmutable($value)->setTime(0, 0) : null;
    }

    public function findLastAggregatedDay(): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT MAX(date) FROM login_failure_daily');

        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    public function deleteDaily(\DateTimeImmutable $day): void
    {
        $this->connection->executeStatement('DELETE FROM login_failure_daily WHERE date = :date', ['date' => $day->format('Y-m-d')]);
    }

    public function deleteEventsBefore(\DateTimeImmutable $before): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM login_failure_event WHERE occurred_at < :before',
            ['before' => $before->format('Y-m-d H:i:s')],
        );
    }

    public function deleteDailyBefore(\DateTimeImmutable $before): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM login_failure_daily WHERE date < :before',
            ['before' => $before->format('Y-m-d')],
        );
    }

    /**
     * Largest number of distinct `$counted` values for one `$grouped` value among the day's attempts.
     *
     * @param array<string, mixed>                      $params
     * @param array<string, ArrayParameterType::STRING> $types
     */
    private function maxDistinct(string $counted, string $grouped, array $params, array $types): int
    {
        return $this->toInt($this->connection->fetchOne(
            \sprintf(
                <<<'SQL'
                    SELECT COALESCE(MAX(n), 0) FROM (
                        SELECT COUNT(DISTINCT %1$s) AS n
                        FROM login_failure_event
                        WHERE occurred_at >= :from AND occurred_at < :to
                          AND kind IN (:attempts) AND %2$s IS NOT NULL
                        GROUP BY %2$s
                    ) per_value
                    SQL,
                $counted,
                $grouped,
            ),
            $params,
            $types,
        ));
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
