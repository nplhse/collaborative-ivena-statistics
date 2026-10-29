<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Demo;

use App\Shared\Infrastructure\Demo\DemoDatabaseTarget;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoDatabaseTargetTest extends TestCase
{
    /** @param array<string, mixed> $params */
    #[DataProvider('safeTargets')]
    public function testAcceptsLocalDemoDatabases(array $params): void
    {
        self::assertSame([], DemoDatabaseTarget::violations($params));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function safeTargets(): iterable
    {
        yield 'loopback' => [['host' => '127.0.0.1', 'dbname' => 'app_demo']];
        yield 'localhost, upper case' => [['host' => 'LOCALHOST', 'dbname' => 'app_demo']];
        yield 'IPv6 loopback' => [['host' => '::1', 'dbname' => 'presentation_demo']];
        yield 'compose service' => [['host' => 'database', 'dbname' => 'app_demo']];
        yield 'unix socket' => [['dbname' => 'app_demo']];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('unsafeTargets')]
    public function testRefusesOtherDatabases(array $params, int $expectedViolations): void
    {
        self::assertCount($expectedViolations, DemoDatabaseTarget::violations($params));
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function unsafeTargets(): iterable
    {
        yield 'dev database' => [['host' => '127.0.0.1', 'dbname' => 'app'], 1];
        yield 'fixtures database' => [['host' => '127.0.0.1', 'dbname' => 'app_fixtures'], 1];
        yield 'suffix only in the middle' => [['host' => '127.0.0.1', 'dbname' => 'app_demo_copy'], 1];
        yield 'remote host' => [['host' => 'db.example.org', 'dbname' => 'app_demo'], 1];
        yield 'remote production database' => [['host' => '10.0.0.5', 'dbname' => 'app'], 2];
        yield 'no database name' => [['host' => '127.0.0.1'], 1];
    }

    public function testReadsHostAndNameFromADatabaseUrl(): void
    {
        $parser = new DsnParser();

        self::assertSame([], DemoDatabaseTarget::violations($parser->parse('postgresql://app:password@127.0.0.1:5432/app_demo?serverVersion=18&charset=utf8')));
        self::assertCount(1, DemoDatabaseTarget::violations($parser->parse('postgresql://app:password@127.0.0.1:5432/app?serverVersion=18&charset=utf8')));
        // SQLite has no host and no database name.
        self::assertCount(1, DemoDatabaseTarget::violations($parser->parse('sqlite:///var/lint.db')));
    }
}
