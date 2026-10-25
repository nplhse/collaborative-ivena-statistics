<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Filesystem;

use App\Storage\Application\Layout\StorageLayout;
use App\Storage\Application\Scan\FileScan;
use App\Storage\Application\Scan\StoragePathClassifier;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\Enum\MeasurementItemStatus;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use App\Storage\Infrastructure\Filesystem\FilesystemStorageLayoutDetector;
use App\Storage\Infrastructure\Filesystem\FilesystemStorageScanner;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;

final class FilesystemStorageScannerTest extends TestCase
{
    private StorageFixtureDirectory $fixture;

    private FilesystemStorageScanner $scanner;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->scanner = new FilesystemStorageScanner(new StoragePathClassifier());
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testClassifiesAStandaloneProject(): void
    {
        $this->fixture->file('src/Kernel.php', 100);
        $this->fixture->file('vendor/pkg/lib.php', 7000);
        $this->fixture->file('var/cache/prod/container.php', 4000);
        $this->fixture->file('var/imports/2026/a.csv', 5000);
        $this->fixture->link('public/latest.csv', $this->fixture->path.'/var/imports/2026/a.csv');

        $scan = $this->scanner->scan(StorageLayout::standalone($this->fixture->path));

        self::assertTrue($scan->complete);
        self::assertSame([], $scan->failures);
        self::assertSame(
            $this->fixture->allocatedSum('var/imports', 'var/imports/2026', 'var/imports/2026/a.csv'),
            $this->item($scan, StorageLocation::Project, null, 'var_imports')->bytes,
        );
        self::assertSame(StorageCategory::Imports, $this->item($scan, StorageLocation::Project, null, 'var_imports')->category);
        self::assertSame(
            $this->fixture->allocatedSum('', 'src', 'src/Kernel.php', 'public'),
            $this->item($scan, StorageLocation::Project, null, 'source')->bytes,
            'the project directory itself and plain source files are source; the symlink is not counted',
        );
        self::assertSame(StorageCategory::Temporary, $this->item($scan, StorageLocation::Project, null, 'var_cache')->category);
        self::assertSame(array_sum(array_map(static fn (StorageMeasurementItem $item): int => (int) $item->bytes, $scan->items)), $scan->totalBytes());
        self::assertSame(\count($scan->items) > 0, $scan->entries > 0);
    }

    public function testAttributesEveryInodeOnceInADeployerLayout(): void
    {
        $this->deployment();

        $scan = $this->scanner->scan(new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect());

        self::assertTrue($scan->complete);

        $sharedImports = $this->item($scan, StorageLocation::Shared, null, 'var_imports');
        self::assertSame($this->fixture->allocatedSum('shared/var/imports', 'shared/var/imports/a.csv'), $sharedImports->bytes);
        self::assertSame([], $sharedImports->flags);
        self::assertSame([MeasurementItemFlag::UnusedSharedPath], $this->item($scan, StorageLocation::Shared, null, 'shared_other')->flags);
        self::assertSame(StorageCategory::Other, $this->item($scan, StorageLocation::Shared, null, 'env_local')->category);

        self::assertNull($this->find($scan, StorageLocation::CurrentRelease, '11', 'var_imports'), 'links into shared/ are not followed');
        self::assertSame(
            $this->fixture->allocatedSum('releases/11/vendor', 'releases/11/vendor/pkg', 'releases/11/vendor/pkg/lib.php'),
            $this->item($scan, StorageLocation::CurrentRelease, '11', 'vendor')->bytes,
        );
        self::assertSame(
            $this->fixture->allocatedSum('releases/10/vendor', 'releases/10/vendor/pkg'),
            $this->item($scan, StorageLocation::PreviousRelease, '10', 'vendor')->bytes,
            'a file hardlinked between releases belongs to the current release only',
        );

        $leftover = $this->item($scan, StorageLocation::PreviousRelease, '10', 'media_uploads');
        self::assertSame($this->fixture->allocatedSum('releases/10/public/uploads/media', 'releases/10/public/uploads/media/old.jpg'), $leftover->bytes);
        self::assertSame([MeasurementItemFlag::LeftoverInRelease], $leftover->flags);
        self::assertSame(StorageCategory::Media, $leftover->category);

        self::assertSame($this->fixture->allocatedSum('.dep', '.dep/releases_log'), $this->item($scan, StorageLocation::DeployRoot, null, 'deploy_metadata')->bytes);
        self::assertSame(
            $this->fixture->allocatedSum('', 'notes.txt'),
            $this->item($scan, StorageLocation::DeployRoot, null, 'unclassified')->bytes,
            'releases/ and shared/ are measured as their own locations; current and html are links',
        );
    }

    public function testMarksUnreadableDirectoriesAsIncomplete(): void
    {
        $this->skipAsRoot();
        $this->deployment();
        chmod($this->fixture->path.'/releases/10/var/cache', 0o000);

        $scan = $this->scanner->scan(new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect());

        self::assertTrue($scan->complete);
        self::assertCount(1, $scan->failures);
        self::assertStringContainsString('releases/10/var/cache: directory cannot be listed', $scan->failures[0]);
        self::assertContains(MeasurementItemFlag::Incomplete, $this->item($scan, StorageLocation::PreviousRelease, '10', 'var_cache')->flags);
    }

    public function testMarksEntriesThatCannotBeReadAsIncomplete(): void
    {
        $this->skipAsRoot();
        $this->deployment();
        // Listable but not searchable: the names are visible, lstat() on them fails.
        chmod($this->fixture->path.'/releases/10/var/cache', 0o444);

        $scan = $this->scanner->scan(new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect());

        self::assertTrue($scan->complete);
        self::assertCount(1, $scan->failures);
        self::assertStringContainsString('releases/10/var/cache/x: cannot be read', $scan->failures[0]);
        self::assertContains(MeasurementItemFlag::Incomplete, $this->item($scan, StorageLocation::PreviousRelease, '10', 'var_cache')->flags);
    }

    public function testAnUnreadableLocationMakesTheScanIncomplete(): void
    {
        $this->skipAsRoot();
        $this->deployment();
        chmod($this->fixture->path.'/releases/10', 0o000);

        $scan = $this->scanner->scan(new FilesystemStorageLayoutDetector($this->fixture->path.'/releases/11')->detect());

        self::assertFalse($scan->complete);
        self::assertNull($scan->totalBytes());
        $failed = $this->item($scan, StorageLocation::PreviousRelease, '10', 'release');
        self::assertSame(MeasurementItemStatus::Failed, $failed->status);
        self::assertNull($failed->bytes);
    }

    public function testAMissingRootFails(): void
    {
        $scan = $this->scanner->scan(StorageLayout::standalone($this->fixture->path.'/missing'));

        self::assertFalse($scan->complete);
        self::assertSame(MeasurementItemStatus::Failed, $this->item($scan, StorageLocation::Project, null, 'project')->status);
        self::assertStringContainsString('not a readable directory', $scan->failures[0]);
    }

    private function deployment(): void
    {
        $this->fixture->file('shared/var/imports/a.csv', 5000);
        $this->fixture->file('shared/public/uploads/media/img.jpg', 3000);
        $this->fixture->file('shared/.env.local', 10);
        $this->fixture->file('shared/old-stuff/file', 2000);

        $this->fixture->file('releases/11/src/Kernel.php', 100);
        $this->fixture->file('releases/11/vendor/pkg/lib.php', 7000);
        $this->fixture->file('releases/11/var/cache/c', 4000);
        $this->fixture->file('releases/11/var/log/l', 500);
        $this->fixture->link('releases/11/var/imports', '../../../shared/var/imports');
        $this->fixture->link('releases/11/public/uploads/media', '../../../../shared/public/uploads/media');
        $this->fixture->link('releases/11/.env.local', '../../shared/.env.local');

        $this->fixture->file('releases/10/src/Kernel.php', 100);
        $this->fixture->hardlink('releases/10/vendor/pkg/lib.php', 'releases/11/vendor/pkg/lib.php');
        $this->fixture->file('releases/10/public/uploads/media/old.jpg', 6000);
        $this->fixture->file('releases/10/var/cache/x', 800);

        $this->fixture->file('.dep/releases_log', 200);
        $this->fixture->file('notes.txt', 300);
        $this->fixture->link('current', 'releases/11');
        $this->fixture->link('html', 'current/public');
    }

    private function item(FileScan $scan, StorageLocation $location, ?string $detail, string $contributor): StorageMeasurementItem
    {
        $item = $this->find($scan, $location, $detail, $contributor);
        self::assertNotNull($item, sprintf('No item %s/%s/%s', $location->value, $detail ?? '-', $contributor));

        return $item;
    }

    private function find(FileScan $scan, StorageLocation $location, ?string $detail, string $contributor): ?StorageMeasurementItem
    {
        foreach ($scan->items as $item) {
            if ($item->location === $location && $item->locationDetail === $detail && $item->contributor === $contributor) {
                return $item;
            }
        }

        return null;
    }

    private function skipAsRoot(): void
    {
        if (\function_exists('posix_getuid') && 0 === posix_getuid()) {
            self::markTestSkipped('Permissions are not enforced for root.');
        }
    }
}
