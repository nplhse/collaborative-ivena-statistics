<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Filesystem;

use App\Storage\Application\Layout\ReleaseDirectory;
use App\Storage\Application\Layout\SharedLink;
use App\Storage\Domain\Enum\MeasurementMode;
use App\Storage\Infrastructure\Filesystem\FilesystemStorageLayoutDetector;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;

final class FilesystemStorageLayoutDetectorTest extends TestCase
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

    public function testAProjectOutsideReleasesIsStandalone(): void
    {
        $project = $this->fixture->directory('app');

        $layout = new FilesystemStorageLayoutDetector($project)->detect();

        self::assertSame(MeasurementMode::Standalone, $layout->mode);
        self::assertSame($project, $layout->root);
        self::assertNull($layout->currentRelease);
        self::assertSame([], $layout->previousReleases);
        self::assertNull($layout->sharedDirectory);
    }

    public function testAMissingProjectDirectoryIsStandalone(): void
    {
        $layout = new FilesystemStorageLayoutDetector($this->fixture->path.'/missing')->detect();

        self::assertSame(MeasurementMode::Standalone, $layout->mode);
        self::assertSame($this->fixture->path.'/missing', $layout->root);
    }

    public function testDetectsTheDeployerLayout(): void
    {
        $this->deployment();
        $this->fixture->file('.dep/releases_log', 0);
        file_put_contents($this->fixture->path.'/.dep/releases_log', implode("\n", [
            '{"created_at":"2026-10-20T08:00:00+0000","release_name":"9","user":"deploy"}',
            'not json',
            '{"created_at":"2026-10-24T08:00:00+0000","release_name":"10"}',
            '{"created_at":"2026-10-25T08:00:00+0000","release_name":"11"}',
        ]));

        $layout = new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect();

        self::assertSame(MeasurementMode::Deployer, $layout->mode);
        self::assertSame($this->fixture->path, $layout->root);
        self::assertEquals(new ReleaseDirectory('11', $this->fixture->path.'/releases/11', new \DateTimeImmutable('2026-10-25T08:00:00+0000')), $layout->currentRelease);
        self::assertSame(['10', '9'], array_map(static fn (ReleaseDirectory $release): string => $release->name, $layout->previousReleases));
        self::assertSame($this->fixture->path.'/shared', $layout->sharedDirectory);
        self::assertEquals([
            new SharedLink('.env.local', '.env.local'),
            new SharedLink('public/uploads/media', 'public/uploads/media'),
            new SharedLink('var/imports', 'var/imports'),
        ], $layout->sharedLinks);
        self::assertSame(['.env.local', 'public/uploads/media', 'var/imports'], $layout->sharedReleasePaths());
        self::assertSame(['.env.local', 'public/uploads/media', 'var/imports'], $layout->linkedSharedPaths());
    }

    public function testOrdersReleasesByNameWithoutLog(): void
    {
        $this->deployment();
        $this->fixture->directory('releases/2');
        $this->fixture->link('releases/latest', $this->fixture->path.'/releases/10');

        $layout = new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect();

        self::assertSame(['10', '9', '2'], array_map(static fn (ReleaseDirectory $release): string => $release->name, $layout->previousReleases));
        self::assertNull($layout->previousReleases[0]->createdAt);
    }

    public function testIgnoresLinksOutsideSharedBrokenLinksAndSkippedDirectories(): void
    {
        $this->deployment();
        $this->fixture->file('elsewhere/file');
        $this->fixture->link('releases/11/config/outside', $this->fixture->path.'/elsewhere/file');
        $this->fixture->link('releases/11/config/broken', $this->fixture->path.'/shared/missing');
        $this->fixture->link('releases/11/vendor/pkg/shared-link', $this->fixture->path.'/shared/var/imports');
        $this->fixture->link('releases/11/a/b/c/d/deep', $this->fixture->path.'/shared/var/imports');

        $layout = new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect();

        self::assertSame(['.env.local', 'public/uploads/media', 'var/imports'], $layout->sharedReleasePaths());
    }

    public function testCurrentPointingElsewhereIsStandalone(): void
    {
        $this->deployment();
        unlink($this->fixture->path.'/current');
        $this->fixture->link('current', $this->fixture->path.'/releases/10');

        $layout = new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect();

        self::assertSame(MeasurementMode::Standalone, $layout->mode);
        self::assertSame($this->fixture->path.'/releases/11', $layout->root);
    }

    public function testMissingSharedDirectoryIsStandalone(): void
    {
        $this->fixture->directory('releases/11');
        $this->fixture->link('current', $this->fixture->path.'/releases/11');

        $layout = new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect();

        self::assertSame(MeasurementMode::Standalone, $layout->mode);
    }

    private function deployment(): void
    {
        $this->fixture->file('shared/.env.local', 10);
        $this->fixture->directory('shared/var/imports');
        $this->fixture->directory('shared/public/uploads/media');
        $this->fixture->directory('releases/9');
        $this->fixture->directory('releases/10');
        $this->fixture->directory('releases/11/var');
        $this->fixture->link('releases/11/var/imports', '../../../shared/var/imports');
        $this->fixture->link('releases/11/public/uploads/media', '../../../../shared/public/uploads/media');
        $this->fixture->link('releases/11/.env.local', '../../shared/.env.local');
        $this->fixture->link('current', 'releases/11');
    }
}
