<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Security\LoginFailure;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\LoginFailureDaily;
use App\User\Domain\Enum\LoginFailureKind;
use App\User\Infrastructure\Security\LoginFailure\LoginFailureAggregation;
use App\User\Infrastructure\Security\LoginFailure\LoginFailureRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(LoginFailureAggregation::class)]
#[CoversClass(LoginFailureRecorder::class)]
final class LoginFailureAggregationTest extends DatabaseKernelTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-28 08:00:00';

    private LoginFailureRecorder $recorder;

    private LoginFailureAggregation $aggregation;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->recorder = self::getContainer()->get(LoginFailureRecorder::class);
        $this->aggregation = self::getContainer()->get(LoginFailureAggregation::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testFinishedDaysAreFoldedIntoDailyCounts(): void
    {
        // 25 Nov: one failure in the last second of the day.
        $this->record('2026-11-25 23:59:59', LoginFailureKind::Failure, 'bob', '192.0.2.99');

        // 26 Nov: "alice" from 10 IPs, one of them refused once; 3 usernames from one IP; the lock threshold once.
        for ($i = 1; $i <= 10; ++$i) {
            $this->record('2026-11-26 00:00:00', LoginFailureKind::Failure, 'Alice', '192.0.2.'.$i);
        }
        $this->record('2026-11-26 09:00:00', LoginFailureKind::Refused, 'alice', '192.0.2.1');
        foreach (['u1', 'u2', 'u3'] as $username) {
            $this->record('2026-11-26 10:00:00', LoginFailureKind::Failure, $username, '198.51.100.7');
        }
        $this->record('2026-11-26 10:00:00', LoginFailureKind::LockThresholdReached, 'alice', null);

        // 27 Nov: nothing. 28 Nov (today): not finished yet.
        $this->record('2026-11-28 01:00:00', LoginFailureKind::Failure, 'carol', '192.0.2.50');

        self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin')));
        $metrics = $this->aggregation->run();

        self::assertSame(['days_processed' => 3, 'failures' => 14, 'flagged_days' => 1, 'events_deleted' => 0, 'daily_rows_deleted' => 0], $metrics);

        $days = $this->dailyRows();
        self::assertSame(['2026-11-25', '2026-11-26', '2026-11-27'], array_keys($days));

        self::assertSame([1, 0, 0, 0, 1, 1, 1, 1], $this->counts($days['2026-11-25']));
        self::assertFalse($days['2026-11-25']->isFlagged());

        // 13 failures and 1 refused; usernames alice, u1, u2, u3 ("Alice" is the same); 10 + 1 IPs;
        // alice from 10 IPs; at most 3 usernames from one IP.
        self::assertSame([13, 1, 1, 0, 4, 11, 10, 3], $this->counts($days['2026-11-26']));
        self::assertTrue($days['2026-11-26']->hasIdentifierFromManyIps());
        self::assertFalse($days['2026-11-26']->hasIpAgainstManyIdentifiers());

        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0], $this->counts($days['2026-11-27']));
    }

    public function testASecondRunWritesYesterdayAgain(): void
    {
        $this->record('2026-11-27 12:00:00', LoginFailureKind::Failure, 'bob', '192.0.2.1');
        self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin')));
        $this->aggregation->run();

        $this->record('2026-11-27 23:30:00', LoginFailureKind::AccountLocked, 'bob', null);
        self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin')));
        $metrics = $this->aggregation->run();

        self::assertSame(1, $metrics['days_processed']);
        $days = $this->dailyRows();
        self::assertSame(['2026-11-27'], array_keys($days));
        self::assertSame([1, 0, 0, 1, 1, 1, 1, 1], $this->counts($days['2026-11-27']));
    }

    public function testRetentionDeletesOldFailuresAndDailyRows(): void
    {
        // Kept for 30 days: today 28 Nov minus 30 days is 29 Oct 00:00.
        $this->record('2026-10-28 23:59:59', LoginFailureKind::Failure, 'old', '192.0.2.1');
        $this->record('2026-10-29 00:00:00', LoginFailureKind::Failure, 'kept', '192.0.2.1');

        // Daily rows for one year: before 28 Nov 2025 they go.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->daily('2025-11-27'));
        $entityManager->persist($this->daily('2025-11-28'));
        $entityManager->persist($this->daily('2026-11-26'));
        $entityManager->flush();

        self::mockTime(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin')));
        $metrics = $this->aggregation->run();

        // Only the day after the last daily row is written.
        self::assertSame(1, $metrics['days_processed']);
        self::assertSame(1, $metrics['events_deleted']);
        self::assertSame(1, $metrics['daily_rows_deleted']);
        self::assertSame(['2025-11-28', '2026-11-26', '2026-11-27'], array_keys($this->dailyRows()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM login_failure_event'));
    }

    public function testUsernameAndIpAreStoredOnlyAsHashes(): void
    {
        $this->record('2026-11-27 12:00:00', LoginFailureKind::Failure, ' Alice ', '192.0.2.1');
        $this->record('2026-11-27 12:00:00', LoginFailureKind::Failure, '', null);

        $rows = $this->connection->fetchAllAssociative('SELECT kind, identifier_hash, ip_hash FROM login_failure_event ORDER BY id');

        self::assertSame('failure', $rows[0]['kind']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['identifier_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['ip_hash']);
        self::assertStringNotContainsString('alice', implode(' ', $rows[0]));
        self::assertStringNotContainsString('192.0.2.1', implode(' ', $rows[0]));
        self::assertNull($rows[1]['identifier_hash']);
        self::assertNull($rows[1]['ip_hash']);
    }

    private function record(string $localTime, LoginFailureKind $kind, ?string $identifier, ?string $ip): void
    {
        self::mockTime(new \DateTimeImmutable($localTime, new \DateTimeZone('Europe/Berlin')));
        $this->recorder->record($kind, $identifier, $ip);
    }

    /** @return array<string, LoginFailureDaily> */
    private function dailyRows(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $rows = [];
        foreach ($entityManager->getRepository(LoginFailureDaily::class)->findBy([], ['date' => 'ASC']) as $row) {
            $rows[$row->getDate()->format('Y-m-d')] = $row;
        }

        return $rows;
    }

    /** @return list<int> failures, refused, lock thresholds, locks, distinct usernames, distinct IPs, max IPs per username, max usernames per IP */
    private function counts(LoginFailureDaily $daily): array
    {
        return [
            $daily->getFailures(),
            $daily->getRefused(),
            $daily->getLockThresholdsReached(),
            $daily->getAccountsLocked(),
            $daily->getDistinctIdentifiers(),
            $daily->getDistinctIps(),
            $daily->getMaxIpsPerIdentifier(),
            $daily->getMaxIdentifiersPerIp(),
        ];
    }

    private function daily(string $date): LoginFailureDaily
    {
        return new LoginFailureDaily(new \DateTimeImmutable($date), 1, 0, 0, 0, 1, 1, 1, 1);
    }
}
