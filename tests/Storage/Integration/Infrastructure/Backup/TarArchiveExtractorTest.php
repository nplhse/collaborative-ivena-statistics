<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupComponent;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\FileComponentSource;
use App\Storage\Infrastructure\Backup\TarArchiveExtractor;
use App\Storage\Infrastructure\Backup\TarFileArchiver;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Runs the system tar, like the server does.
 */
final class TarArchiveExtractorTest extends TestCase
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

    public function testExtractsWhatTheArchiverPacked(): void
    {
        $this->fixture->file('src/a.csv', 3);
        $this->fixture->file('src/2026/b.csv', 5);
        $out = $this->fixture->directory('out');
        new TarFileArchiver($this->fixture->path)->archive(new FileComponentSource(BackupComponent::Media, $this->fixture->path.'/src'), $out.'/x.tar.gz', $out.'/x.sha256');
        $target = $this->fixture->directory('restored');

        new TarArchiveExtractor()->extract($out.'/x.tar.gz', $target);

        self::assertSame(str_repeat('x', 3), file_get_contents($target.'/a.csv'));
        self::assertSame(str_repeat('x', 5), file_get_contents($target.'/2026/b.csv'));
    }

    public function testReportsAnArchiveTarCannotRead(): void
    {
        $archive = $this->fixture->file('broken.tar.gz', 100);

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessageMatches('/^tar could not extract broken\.tar\.gz \(exit code \d+\)/');

        new TarArchiveExtractor()->extract($archive, $this->fixture->directory('restored'));
    }
}
