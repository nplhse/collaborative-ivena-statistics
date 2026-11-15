<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\TimestampMigrationComparator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

final class TimestampMigrationComparatorTest extends TestCase
{
    public function testOrdersByTimestampAcrossNamespaces(): void
    {
        $versions = [
            new Version('DoctrineMigrations\Version20261116090000'),
            new Version(\App\Tests\Fixtures\Plugin\InpatientStay\Migrations\Version20261115200000::class),
            new Version('DoctrineMigrations\Version20261114220511'),
        ];

        usort($versions, new TimestampMigrationComparator()->compare(...));

        self::assertSame([
            'DoctrineMigrations\Version20261114220511',
            \App\Tests\Fixtures\Plugin\InpatientStay\Migrations\Version20261115200000::class,
            'DoctrineMigrations\Version20261116090000',
        ], array_map(strval(...), $versions));
    }

    public function testEqualTimestampsAreOrderedByTheFullName(): void
    {
        $comparator = new TimestampMigrationComparator();
        $core = new Version('DoctrineMigrations\Version20261115200000');
        $plugin = new Version('Plugin\InpatientStay\Version20261115200000');

        self::assertSame(-1, $comparator->compare($core, $plugin));
        self::assertSame(1, $comparator->compare($plugin, $core));
        self::assertSame(0, $comparator->compare($core, new Version('DoctrineMigrations\Version20261115200000')));
    }

    public function testCoreMigrationsKeepTheirAlphabeticalOrder(): void
    {
        $names = array_map(static fn (string $file): string => 'DoctrineMigrations\\'.basename($file, '.php'), glob(\dirname(__DIR__, 5).'/migrations/Version*.php') ?: []);
        $alphabetical = $names;
        sort($alphabetical);
        $versions = array_map(static fn (string $name): Version => new Version($name), $names);

        usort($versions, new TimestampMigrationComparator()->compare(...));

        self::assertNotEmpty($names);
        self::assertSame($alphabetical, array_map(strval(...), $versions));
    }

    public function testANameWithoutTimestampIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Migration "DoctrineMigrations\AddUsers" must be named');

        new TimestampMigrationComparator()->compare(new Version('DoctrineMigrations\AddUsers'), new Version('DoctrineMigrations\Version20261115200000'));
    }
}
