<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Doctrine;

use App\Tests\Support\Foundry\DamaStaticConnections;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Guard for #887: after Foundry's migrate reset terminated DAMA's static connection, the next
 * kernel must open a fresh connection instead of reusing the dead one.
 *
 * Foundry resets the database only once per process, so the test simulates the reset's
 * `pg_terminate_backend()` on the static connection itself.
 */
final class DamaStaticConnectionsTest extends DatabaseKernelTestCase
{
    public function testForgetAllReplacesTerminatedStaticConnection(): void
    {
        $terminatedPid = $this->backendPid();

        try {
            $this->connection()->executeQuery('SELECT pg_terminate_backend(pg_backend_pid())');
        } catch (DbalException) {
            // The backend closes the connection while answering; this is the expected outcome.
        }

        self::ensureKernelShutdown();

        try {
            $this->backendPid();
            self::fail('DAMA reused the terminated static connection without an error; check whether DamaStaticConnections is still needed.');
        } catch (DbalException) {
            // Without DamaStaticConnections::forgetAll(), DAMA hands out the dead connection again.
        }

        self::ensureKernelShutdown();
        DamaStaticConnections::forgetAll();

        self::assertNotSame($terminatedPid, $this->backendPid());
    }

    private function backendPid(): int
    {
        $pid = $this->connection()->fetchOne('SELECT pg_backend_pid()');
        self::assertIsInt($pid);

        return $pid;
    }

    private function connection(): Connection
    {
        if (!self::$booted) {
            self::bootKernel();
        }

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
