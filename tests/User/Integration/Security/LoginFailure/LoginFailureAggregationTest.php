<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Security\LoginFailure;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\LoginFailureDaily;
use App\User\Infrastructure\Security\LoginFailure\LoginFailureAggregation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * One run of the daily job (#1487): which days it writes is a unit test of LoginFailureDaily::daysToFold(), the counts
 * are tests of LoginFailureDayQuery; this test proves the writing and the retention together.
 */
#[CoversClass(LoginFailureAggregation::class)]
final class LoginFailureAggregationTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    public function testARunWritesFinishedDaysAndDeletesExpiredRows(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Daily rows: one expired (before 28 Nov 2025), the last one on 25 Nov.
        $entityManager->persist(new LoginFailureDaily(new \DateTimeImmutable('2025-11-27'), 1, 0, 0, 0, 1, 1, 1, 1));
        $entityManager->persist(new LoginFailureDaily(new \DateTimeImmutable('2026-11-25'), 1, 0, 0, 0, 1, 1, 1, 1));
        $entityManager->flush();
        // Single failures: one expired (before 29 Oct), one on 26 Nov, one today.
        $this->insertFailure($connection, '2026-10-28 23:59:59');
        $this->insertFailure($connection, '2026-11-26 12:00:00');
        $this->insertFailure($connection, '2026-11-28 01:00:00');
        self::mockTime(new \DateTimeImmutable('2026-11-28 08:00:00', new \DateTimeZone('Europe/Berlin')));

        $metrics = self::getContainer()->get(LoginFailureAggregation::class)->run();

        self::assertSame(['days_processed' => 2, 'failures' => 1, 'flagged_days' => 0, 'events_deleted' => 1, 'daily_rows_deleted' => 1], $metrics);
        self::assertSame(
            ['2026-11-25' => 1, '2026-11-26' => 1, '2026-11-27' => 0],
            $connection->fetchAllKeyValue('SELECT date, failures FROM login_failure_daily ORDER BY date'),
        );
    }

    private function insertFailure(Connection $connection, string $occurredAt): void
    {
        $connection->insert('login_failure_event', ['occurred_at' => $occurredAt, 'kind' => 'failure', 'identifier_hash' => 'id-alice', 'ip_hash' => 'ip-1']);
    }
}
