<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Export;

use App\Research\Infrastructure\Export\ExportConnection;
use App\Research\Infrastructure\Health\ExportConnectionHealthCheck;
use App\Shared\Application\Health\HealthCheckStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Proxy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Whether the export connection can only read (ADR 045). The test environment shares the application user, which can
 * write; a role created inside the test transaction stands in for the hosting's read-only user and goes with the
 * rollback.
 */
final class ExportConnectionTest extends KernelTestCase
{
    public function testTheApplicationUserCountsAsWritable(): void
    {
        self::assertSame(ExportConnection::WRITABLE, self::getContainer()->get(ExportConnection::class)->state());

        $result = self::getContainer()->get(ExportConnectionHealthCheck::class)->check();
        self::assertSame(HealthCheckStatus::Healthy, $result->status, 'RESEARCH_EXPECT_READ_ONLY=0 in the test environment');
        self::assertSame('writable', $result->value);

        $production = new ExportConnectionHealthCheck(self::getContainer()->get(ExportConnection::class), null);
        self::assertSame(HealthCheckStatus::Degraded, $production->check()->status, 'in production a writable connection is degraded');
    }

    public function testAMissingOrFailingConnectionIsReported(): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        self::assertSame(ExportConnection::DISABLED, new ExportConnection($registry, '')->state());
        self::assertNull(new ExportConnection($registry, null)->get());

        $failing = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'nobody', 'dbname' => 'nothing']);
        $unreachable = new ExportConnection(new class($failing) extends AbstractManagerRegistry {
            public function __construct(private readonly Connection $failing)
            {
                parent::__construct('test', ['research' => 'research'], [], 'research', 'default', Proxy::class);
            }

            #[\Override]
            protected function getService(string $name): Connection
            {
                return $this->failing;
            }

            #[\Override]
            protected function resetService(string $name): void
            {
            }
        }, 'postgresql://nobody@127.0.0.1:1/nothing');
        self::assertSame(ExportConnection::UNREACHABLE, $unreachable->state());
    }

    public function testARoleThatOnlyReadsCountsAsReadOnly(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $role = 'research_ro_'.bin2hex(random_bytes(4));
        $connection->executeStatement(\sprintf('CREATE ROLE %s NOLOGIN', $role));
        $connection->executeStatement(\sprintf('GRANT USAGE ON SCHEMA public TO %s', $role));
        $connection->executeStatement(\sprintf('GRANT SELECT ON ALL TABLES IN SCHEMA public TO %s', $role));
        $connection->executeStatement(\sprintf('SET LOCAL ROLE %s', $role));

        try {
            self::assertSame(ExportConnection::READ_ONLY, ExportConnection::inspect($connection));
            self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM allocation'), 'it reads');

            $connection->executeStatement('RESET ROLE');
            $connection->executeStatement(\sprintf('GRANT INSERT ON allocation TO %s', $role));
            $connection->executeStatement(\sprintf('SET LOCAL ROLE %s', $role));
            self::assertSame(ExportConnection::WRITABLE, ExportConnection::inspect($connection), 'one writable table is enough');
        } finally {
            $connection->executeStatement('RESET ROLE');
        }
    }
}
