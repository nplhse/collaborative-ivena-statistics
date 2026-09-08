<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\AnalyticsRetentionResult;
use App\Analytics\Domain\AnalyticsCalendar;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AnalyticsRawRetentionService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
        #[Autowire(param: 'analytics.raw_retention_days')]
        private int $retentionDays,
    ) {
    }

    public function retentionDays(): int
    {
        return max(1, $this->retentionDays);
    }

    public function purgeExpiredRaw(bool $dryRun = false): AnalyticsRetentionResult
    {
        $cutoff = AnalyticsCalendar::daysAgo($this->retentionDays());
        $eligibleDates = $this->eligibleDates($cutoff);

        if ([] === $eligibleDates) {
            return new AnalyticsRetentionResult(0, 0, []);
        }

        $dateStrings = array_map(
            static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'),
            $eligibleDates,
        );

        if ($dryRun) {
            return new AnalyticsRetentionResult(
                requestsDeleted: $this->countRawForDates('analytics_request', $eligibleDates),
                eventsDeleted: $this->countRawForDates('analytics_product_event', $eligibleDates),
                datesCleaned: $dateStrings,
            );
        }

        $requestsDeleted = 0;
        $eventsDeleted = 0;
        foreach ($eligibleDates as $date) {
            [$from, $to] = AnalyticsCalendar::dayBounds($date);
            $requestsDeleted += $this->deleteRawBetween('analytics_request', $from, $to);
            $eventsDeleted += $this->deleteRawBetween('analytics_product_event', $from, $to);
        }

        return new AnalyticsRetentionResult(
            requestsDeleted: $requestsDeleted,
            eventsDeleted: $eventsDeleted,
            datesCleaned: $dateStrings,
        );
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function eligibleDates(\DateTimeImmutable $cutoff): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT date FROM analytics_aggregation_run WHERE date < :cutoff ORDER BY date ASC',
            ['cutoff' => $cutoff->format('Y-m-d')],
        );

        $dates = [];
        foreach ($rows as $value) {
            $dates[] = AnalyticsCalendar::startOfDay(
                new \DateTimeImmutable((string) $value, AnalyticsCalendar::timezone()),
            );
        }

        return $dates;
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     */
    private function countRawForDates(string $table, array $dates): int
    {
        $total = 0;
        foreach ($dates as $date) {
            [$from, $to] = AnalyticsCalendar::dayBounds($date);
            $total += $this->toInt($this->connection->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE occurred_at >= :from AND occurred_at < :to',
                    $table,
                ),
                [
                    'from' => $from->format('Y-m-d H:i:s'),
                    'to' => $to->format('Y-m-d H:i:s'),
                ],
            ));
        }

        return $total;
    }

    private function deleteRawBetween(string $table, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE occurred_at >= :from AND occurred_at < :to',
                $table,
            ),
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
