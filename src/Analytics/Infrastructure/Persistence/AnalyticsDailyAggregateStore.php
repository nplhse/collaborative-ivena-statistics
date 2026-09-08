<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final readonly class AnalyticsDailyAggregateStore
{
    private const array TABLES = [
        'analytics_request_daily',
        'analytics_event_daily',
        'analytics_filter_param_daily',
        'analytics_filter_area_daily',
        'analytics_transition_daily',
        'analytics_session_boundary_daily',
        'analytics_uniques_daily',
        'analytics_aggregation_run',
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function deleteByDate(\DateTimeImmutable $date): void
    {
        $dateString = $date->format('Y-m-d');
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE date = :date', $table),
                ['date' => $dateString],
            );
        }
    }
}
