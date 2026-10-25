<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Filesystem;

use App\Storage\Infrastructure\Filesystem\DeployerReleasesLog;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;

final class DeployerReleasesLogTest extends TestCase
{
    private StorageFixtureDirectory $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testReadsReleaseDatesAndSkipsUnreadableLines(): void
    {
        $this->fixture->file('.dep/releases_log', 0);
        file_put_contents($this->fixture->path.'/.dep/releases_log', implode("\n", [
            '{"created_at":"2026-10-24T08:00:00+0000","release_name":"10"}',
            '{"created_at":"2026-10-25T08:00:00+0000","release_name":11}',
            '{broken',
            '"just a string"',
            '{"release_name":"12"}',
            '{"created_at":"not a date","release_name":"13"}',
            '',
        ]));

        $dates = new DeployerReleasesLog()->read($this->fixture->path);

        self::assertSame(
            [10 => '2026-10-24 08:00', 11 => '2026-10-25 08:00'],
            array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d H:i'), $dates),
        );
    }

    public function testWithoutLogThereAreNoDates(): void
    {
        self::assertSame([], new DeployerReleasesLog()->read($this->fixture->path));
    }
}
