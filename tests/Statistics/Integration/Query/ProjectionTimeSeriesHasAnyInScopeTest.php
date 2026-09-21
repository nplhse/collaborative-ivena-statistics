<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Query;

use App\Statistics\Infrastructure\Query\ProjectionTimeSeriesQuery;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class ProjectionTimeSeriesHasAnyInScopeTest extends KernelTestCase
{
    public function testIgnoresPeriodAndMatchesHospitalOrDispatchAreaScope(): void
    {
        self::bootKernel();
        $query = self::getContainer()->get(ProjectionTimeSeriesQuery::class);
        self::assertInstanceOf(ProjectionTimeSeriesQuery::class, $query);

        self::assertFalse($query->hasAnyInScope(null));
        self::assertFalse($query->hasAnyInScope([]));

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(<<<'SQL'
            INSERT INTO allocation_stats_projection (
                id, import_id, hospital_id, state_id, dispatch_area_id,
                speciality_id, department_id, assignment_id,
                created_at, arrival_at,
                created_year, created_quarter, created_month, created_week, created_day, created_weekday, created_hour,
                day_time_bucket_code, shift_bucket_code, transport_time_minutes, urgency_code
            ) VALUES (
                1, 1, 7, 1, 3,
                1, 1, 1,
                '2024-03-01 08:00:00', '2024-03-01 08:30:00',
                2024, 1, 3, 9, 1, 5, 8,
                1, 1, 30, 1
            )
            SQL);

        self::assertTrue($query->hasAnyInScope(null));
        self::assertTrue($query->hasAnyInScope([7]));
        self::assertFalse($query->hasAnyInScope([8]));
        self::assertTrue($query->hasAnyInScope(null, 3));
        self::assertFalse($query->hasAnyInScope(null, 4));
        self::assertFalse($query->hasAnyInScope([], 3));
    }
}
