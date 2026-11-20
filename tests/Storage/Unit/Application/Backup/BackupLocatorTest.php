<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\BackupLocator;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;

final class BackupLocatorTest extends TestCase
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

    public function testListsCompleteBackupsNewestFirst(): void
    {
        $this->fixture->file('backups/ivena-stats-full-20261101T010000Z/manifest.json', 2);
        $this->fixture->file('backups/ivena-stats-full-20261120T010000Z/manifest.json', 2);
        $this->fixture->file('backups/ivena-stats-full-20261110T010000Z/manifest.json', 2);
        $this->fixture->file('backups/.ivena-stats-full-20261121T010000Z.partial/manifest.json', 2);
        $this->fixture->file('backups/ivena-stats-full-20261122T010000Z/database.dump', 2);
        $this->fixture->file('backups/ivena-stats-db-20261123-010000.dump', 2);

        self::assertSame([
            $this->fixture->path.'/backups/ivena-stats-full-20261120T010000Z',
            $this->fixture->path.'/backups/ivena-stats-full-20261110T010000Z',
            $this->fixture->path.'/backups/ivena-stats-full-20261101T010000Z',
        ], $this->locator()->all());
    }

    public function testResolvesTheNewestANameOrAPath(): void
    {
        $this->fixture->file('backups/ivena-stats-full-20261101T010000Z/manifest.json', 2);
        $this->fixture->file('backups/ivena-stats-full-20261120T010000Z/manifest.json', 2);
        $this->fixture->file('elsewhere/copy/manifest.json', 2);
        $locator = $this->locator();

        self::assertSame($this->fixture->path.'/backups/ivena-stats-full-20261120T010000Z', $locator->resolve(null));
        self::assertSame($this->fixture->path.'/backups/ivena-stats-full-20261101T010000Z', $locator->resolve('ivena-stats-full-20261101T010000Z'));
        self::assertSame($this->fixture->path.'/elsewhere/copy', $locator->resolve('elsewhere/copy'));
        self::assertSame($this->fixture->path.'/elsewhere/copy', $locator->resolve($this->fixture->path.'/elsewhere/copy/'));
    }

    public function testExplainsWhenNothingMatches(): void
    {
        $locator = $this->locator();

        try {
            $locator->resolve(null);
            self::fail('Without backups nothing can be resolved.');
        } catch (BackupFailed $failure) {
            self::assertSame(sprintf('There is no complete backup in %s/backups.', $this->fixture->path), $failure->getMessage());
        }

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('The backup ivena-stats-full-x does not exist.');
        $locator->resolve('ivena-stats-full-x');
    }

    private function locator(): BackupLocator
    {
        return new BackupLocator('backups', $this->fixture->path);
    }
}
