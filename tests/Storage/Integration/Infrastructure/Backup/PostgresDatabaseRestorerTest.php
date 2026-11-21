<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Infrastructure\Backup\PostgresDatabaseRestorer;
use App\Storage\Infrastructure\Backup\PostgresTools;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The schema switch against a scratch database of its own: renaming schema public in the test database would
 * break every other test. pg_restore is replaced (CI has no client tools of the server version); the stand-in
 * creates the restored tables with SQL.
 */
final class PostgresDatabaseRestorerTest extends KernelTestCase
{
    private Connection $admin;

    private Connection $scratch;

    private string $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $params = self::getContainer()->get(Connection::class)->getParams();
        $this->admin = DriverManager::getConnection($params);
        $this->database = substr((string) ($params['dbname'] ?? 'app'), 0, 40).'_restore';

        $this->admin->executeStatement(sprintf('DROP DATABASE IF EXISTS %s', $this->database));
        $this->admin->executeStatement(sprintf('CREATE DATABASE %s', $this->database));
        $this->scratch = DriverManager::getConnection(['dbname' => $this->database] + $params);

        $this->scratch->executeStatement('CREATE TABLE public.allocation (id INT)');
        $this->scratch->executeStatement('INSERT INTO public.allocation VALUES (1), (2), (3)');
    }

    protected function tearDown(): void
    {
        $this->scratch->close();
        $this->admin->executeStatement(sprintf('DROP DATABASE IF EXISTS %s', $this->database));
        $this->admin->close();
        parent::tearDown();
    }

    public function testRestoresIntoAFreshSchemaAndDropsTheOldOne(): void
    {
        $lists = [];
        $restorer = $this->restorer(static function (string $list) use (&$lists): void {
            $lists[] = $list;
        });

        $stash = $restorer->stash('20261121t080000z');
        self::assertSame('pre_restore_20261121t080000z', $stash);
        self::assertSame([], $restorer->tableRows(), 'The new schema starts empty.');

        $restorer->restore('/backups/x/database.dump');
        self::assertSame(['hospital' => 2], $restorer->tableRows());
        self::assertSame(
            "3; 0 0 TABLE public hospital app\n4; 0 0 TABLE DATA public hospital app\n8; 0 0 TABLE public lock_keys app\n",
            $lists[0],
            'Extensions, PostGIS tables, schema public and the rows of the lock table are not restored.',
        );

        $restorer->discard($stash);
        self::assertSame(['public'], $this->schemas());
    }

    public function testRowCountsLeaveOutTheLockTable(): void
    {
        $this->scratch->executeStatement('CREATE TABLE public.lock_keys (key_id VARCHAR(64) PRIMARY KEY, key_token VARCHAR(44), key_expiration INT)');
        $this->scratch->executeStatement("INSERT INTO public.lock_keys VALUES ('backup-maintenance', 'token', 1795255789)");

        self::assertSame(['allocation' => 3], $this->restorer()->tableRows(), 'locks are runtime state, not counted in the manifest (#1452)');
    }

    public function testClearDropsTheCurrentSchemaWithoutACopy(): void
    {
        $restorer = $this->restorer();

        $restorer->clear();
        self::assertSame([], $restorer->tableRows());
        self::assertSame(['public'], $this->schemas());

        $restorer->restore('/backups/x/database.dump');
        self::assertSame(['hospital' => 2], $restorer->tableRows());
    }

    public function testRollbackBringsTheOldSchemaBack(): void
    {
        $restorer = $this->restorer();

        $stash = $restorer->stash('20261121t080000z');
        $restorer->restore('/backups/x/database.dump');
        $restorer->rollback($stash);

        self::assertSame(['allocation' => 3], $restorer->tableRows());
        self::assertSame(['public'], $this->schemas());
    }

    public function testRefusesAStashThatAlreadyExists(): void
    {
        $this->scratch->executeStatement('CREATE SCHEMA pre_restore_20261121t080000z');

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('The schema pre_restore_20261121t080000z already exists.');

        $this->restorer()->stash('20261121t080000z');
    }

    public function testPreflightRefusesWhileQueuesHoldWork(): void
    {
        self::assertSame('pg_restore 18', $this->restorer()->preflight(), 'Without a queue table there is no work.');

        $this->scratch->executeStatement('CREATE TABLE messenger_messages (id SERIAL, queue_name VARCHAR(190))');
        $this->scratch->executeStatement("INSERT INTO messenger_messages (queue_name) VALUES ('failed'), ('async'), ('async'), ('import')");

        try {
            $this->restorer()->preflight();
            self::fail('Queued work should stop the restore.');
        } catch (BackupFailed $failure) {
            self::assertSame('The message queues still hold work: async (2), import (1).', $failure->getMessage());
        }

        $this->scratch->executeStatement("DELETE FROM messenger_messages WHERE queue_name <> 'failed'");
        self::assertSame('pg_restore 18', $this->restorer()->preflight());
    }

    public function testExplainsARollbackWithoutAStash(): void
    {
        $restorer = $this->restorer();

        try {
            $restorer->rollback('pre_restore_missing');
            self::fail('A rollback without the stashed schema should fail.');
        } catch (BackupFailed $failure) {
            self::assertStringStartsWith('The previous schema could not be moved back.', $failure->getMessage());
        }

        self::assertSame(['allocation' => 3], $restorer->tableRows(), 'The failed rollback changed nothing.');
        self::assertSame('app on db:5432', $restorer->target());
    }

    public function testRefusesAnOpenTransactionAndAnInvalidSuffix(): void
    {
        $restorer = $this->restorer();

        try {
            $restorer->stash('2026-11-21');
            self::fail('A suffix outside [a-z0-9_] must be refused.');
        } catch (BackupFailed $failure) {
            self::assertSame('"pre_restore_2026-11-21" is not a valid schema name.', $failure->getMessage());
        }

        $this->scratch->beginTransaction();
        try {
            $this->expectException(BackupFailed::class);
            $this->expectExceptionMessage('A database transaction is already open.');
            $restorer->preflight();
        } finally {
            $this->scratch->rollBack();
        }
    }

    /**
     * @param ?\Closure(string): void $onRestore receives the filtered table of contents
     */
    private function restorer(?\Closure $onRestore = null): PostgresDatabaseRestorer
    {
        $toc = <<<'TOC'
            ;
            ; Archive created at 2026-11-20 18:38:24 UTC
            2; 2615 2200 SCHEMA - public pg_database_owner
            5; 3079 16385 EXTENSION - postgis
            6; 0 0 COMMENT - EXTENSION postgis
            3; 0 0 TABLE public hospital app
            7; 0 0 TABLE public spatial_ref_sys app
            4; 0 0 TABLE DATA public hospital app
            8; 0 0 TABLE public lock_keys app
            9; 0 0 TABLE DATA public lock_keys app
            TOC;

        $scratch = $this->scratch;
        $tools = $this->createStub(PostgresTools::class);
        $tools->method('version')->willReturn('pg_restore 18');
        $tools->method('target')->willReturn('app on db:5432');
        $tools->method('listDump')->willReturn($toc);
        $tools->method('restore')->willReturnCallback(static function (string $dumpFile, string $list) use ($scratch, $onRestore): void {
            if ($onRestore instanceof \Closure) {
                $onRestore(implode("\n", array_filter(explode("\n", $list), static fn (string $line): bool => '' !== $line && !str_starts_with($line, ';')))."\n");
            }
            $scratch->executeStatement('CREATE TABLE public.hospital (id INT)');
            $scratch->executeStatement('INSERT INTO public.hospital VALUES (1), (2)');
        });

        return new PostgresDatabaseRestorer($this->scratch, $tools);
    }

    /**
     * @return list<string>
     */
    private function schemas(): array
    {
        /* @var list<string> */
        return $this->scratch->fetchFirstColumn("SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg\\_%' AND nspname <> 'information_schema' ORDER BY nspname");
    }
}
