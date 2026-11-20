<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupComponent;
use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Backup\FileComponentSource;
use App\Storage\Infrastructure\Backup\TarFileArchiver;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the system tar, like the server does.
 */
final class TarFileArchiverTest extends TestCase
{
    private StorageFixtureDirectory $fixture;

    private string $output;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->output = $this->fixture->directory('out');
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testPacksTheRegularFilesAndListsTheirChecksums(): void
    {
        $this->fixture->file('src/a.csv', 3);
        $this->fixture->file('src/2026/b.csv', 5);
        $this->fixture->file('src/.hidden', 1);
        $this->fixture->file('src/pending/7/upload.bin', 9);
        $this->fixture->file('src/2026/pending/kept.csv', 2);

        $result = $this->archiver()->archive($this->source(['pending']), $this->output.'/x.tar.gz', $this->output.'/x.sha256');

        self::assertSame(4, $result->files);
        self::assertSame(11, $result->bytes);
        self::assertSame(0, $result->skippedLinks);
        self::assertFalse($result->sourceMissing);

        $hash = static fn (int $bytes): string => hash('sha256', str_repeat('x', $bytes));
        self::assertSame(
            $hash(1).'  .hidden'."\n"
            .$hash(5).'  2026/b.csv'."\n"
            .$hash(2).'  2026/pending/kept.csv'."\n"
            .$hash(3).'  a.csv'."\n",
            file_get_contents($this->output.'/x.sha256'),
        );

        self::assertSame(['./.hidden', './2026/b.csv', './2026/pending/kept.csv', './a.csv'], $this->entries($this->output.'/x.tar.gz'));
        self::assertFileDoesNotExist($this->output.'/x.tar.gz.list');
    }

    public function testNeverFollowsOrPacksSymbolicLinks(): void
    {
        $this->fixture->file('src/a.csv', 3);
        $this->fixture->file('elsewhere/secret.txt', 4);
        $this->fixture->link('src/link.txt', $this->fixture->path.'/elsewhere/secret.txt');
        $this->fixture->link('src/linked-dir', $this->fixture->path.'/elsewhere');

        $result = $this->archiver()->archive($this->source(), $this->output.'/x.tar.gz', $this->output.'/x.sha256');

        self::assertSame(1, $result->files);
        self::assertSame(2, $result->skippedLinks);
        self::assertSame(['./a.csv'], $this->entries($this->output.'/x.tar.gz'));
    }

    public function testAnEmptyComponentWritesNoArchive(): void
    {
        $this->fixture->directory('src/empty');

        $result = $this->archiver()->archive($this->source(), $this->output.'/x.tar.gz', $this->output.'/x.sha256');

        self::assertFalse($result->hasArchive());
        self::assertFalse($result->sourceMissing);
        self::assertFileDoesNotExist($this->output.'/x.tar.gz');
        self::assertFileDoesNotExist($this->output.'/x.sha256');
    }

    public function testAMissingSourceDirectoryIsMarked(): void
    {
        $result = $this->archiver()->archive($this->source(), $this->output.'/x.tar.gz', $this->output.'/x.sha256');

        self::assertSame(0, $result->files);
        self::assertTrue($result->sourceMissing);
    }

    public function testRejectsAFileNameWithALineBreak(): void
    {
        $this->fixture->file("src/bad\nname.csv", 1);

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('contains a line break');

        $this->archiver()->archive($this->source(), $this->output.'/x.tar.gz', $this->output.'/x.sha256');
    }

    public function testFailsWhenTarCannotWriteTheArchive(): void
    {
        $this->fixture->file('src/a.csv', 3);

        $this->expectException(BackupFailed::class);
        $this->expectExceptionMessage('tar failed for imports');

        // The archive path is an existing directory, so tar cannot create the file.
        $this->archiver()->archive($this->source(), $this->fixture->directory('out/x.tar.gz'), $this->output.'/x.sha256');
    }

    public function testPreflightFindsTar(): void
    {
        $this->archiver()->preflight();

        $this->addToAssertionCount(1);
    }

    private function archiver(): TarFileArchiver
    {
        return new TarFileArchiver($this->fixture->path);
    }

    /**
     * @param list<string> $excluded
     */
    private function source(array $excluded = []): FileComponentSource
    {
        return new FileComponentSource(BackupComponent::Imports, $this->fixture->path.'/src', $excluded);
    }

    /**
     * @return list<string>
     */
    private function entries(string $archive): array
    {
        $process = new Process(['tar', '-tzf', $archive]);
        $process->mustRun();
        $entries = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        sort($entries);

        return $entries;
    }
}
