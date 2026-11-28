<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Security\LoginFailure;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Infrastructure\Query\LoginFailureDayQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * SQL of the daily counters (#1487) on small fixtures. Hashes are readable stand-ins ("id-alice", "ip-1").
 */
#[CoversClass(LoginFailureDayQuery::class)]
final class LoginFailureDayQueryTest extends DatabaseKernelTestCase
{
    private LoginFailureDayQuery $query;

    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(LoginFailureDayQuery::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testEachKindIsCountedOnItsOwn(): void
    {
        $this->insert('2026-11-26 10:00:00', 'failure', 'id-alice', 'ip-1');
        $this->insert('2026-11-26 10:00:00', 'refused', 'id-alice', 'ip-1');
        $this->insert('2026-11-26 10:00:00', 'lock_threshold', 'id-alice', null);
        $this->insert('2026-11-26 10:00:00', 'account_locked', 'id-bob', null);

        $counts = $this->countDay('2026-11-26');

        self::assertSame([1, 1, 1, 1], [$counts['failures'], $counts['refused'], $counts['lock_thresholds'], $counts['locks']]);
    }

    public function testTheDayRunsFromMidnightToMidnight(): void
    {
        $this->insert('2026-11-25 23:59:59', 'failure', 'id-alice', 'ip-1');
        $this->insert('2026-11-26 00:00:00', 'failure', 'id-alice', 'ip-1');
        $this->insert('2026-11-26 23:59:59', 'failure', 'id-alice', 'ip-1');
        $this->insert('2026-11-27 00:00:00', 'failure', 'id-alice', 'ip-1');

        $counts = $this->countDay('2026-11-26');

        self::assertSame(2, $counts['failures']);
    }

    public function testDistinctValuesAndMaximaLookAtAttemptsOnly(): void
    {
        // Attempts: alice from ip-1 and ip-2, alice refused from ip-1, bob from ip-1. The lock threshold is no attempt.
        $this->insert('2026-11-26 10:00:00', 'failure', 'id-alice', 'ip-1');
        $this->insert('2026-11-26 10:01:00', 'failure', 'id-alice', 'ip-2');
        $this->insert('2026-11-26 10:02:00', 'refused', 'id-alice', 'ip-1');
        $this->insert('2026-11-26 10:03:00', 'failure', 'id-bob', 'ip-1');
        $this->insert('2026-11-26 10:04:00', 'lock_threshold', 'id-carol', null);

        $counts = $this->countDay('2026-11-26');

        self::assertSame(
            ['distinct_identifiers' => 2, 'distinct_ips' => 2, 'max_ips_per_identifier' => 2, 'max_identifiers_per_ip' => 2],
            array_intersect_key($counts, array_flip(['distinct_identifiers', 'distinct_ips', 'max_ips_per_identifier', 'max_identifiers_per_ip'])),
        );
    }

    public function testADayWithoutFailuresCountsZero(): void
    {
        $counts = $this->countDay('2026-11-26');

        self::assertSame(array_fill_keys(array_keys($counts), 0), $counts);
    }

    private function insert(string $occurredAt, string $kind, ?string $identifierHash, ?string $ipHash): void
    {
        $this->connection->insert('login_failure_event', [
            'occurred_at' => $occurredAt,
            'kind' => $kind,
            'identifier_hash' => $identifierHash,
            'ip_hash' => $ipHash,
        ]);
    }

    /** @return array<string, int> */
    private function countDay(string $day): array
    {
        $from = new \DateTimeImmutable($day);

        return $this->query->countDay($from, $from->modify('+1 day'));
    }
}
