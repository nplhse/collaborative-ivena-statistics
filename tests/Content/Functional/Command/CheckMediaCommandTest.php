<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Command;

use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Media\MediaStorage;
use App\Content\Infrastructure\Media\MediaStorageMigrator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CheckMediaCommandTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        new Filesystem()->remove(self::getContainer()->get(MediaStorage::class)->root());
    }

    public function testIntactFilesPass(): void
    {
        MediaFactory::createMany(2);

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('The media files match the media library.', $tester->getDisplay());
    }

    public function testAMissingFileFailsAndIsNamed(): void
    {
        $media = MediaFactory::createOne();
        unlink(self::getContainer()->get(MediaStorage::class)->absolutePath((string) $media->getStorageKey()));

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--full' => true]));
        self::assertStringContainsString((string) $media->getStorageKey(), $tester->getDisplay());
    }

    public function testADryRunNamesWhatWouldBeDeleted(): void
    {
        MediaFactory::createOne();
        $orphan = $this->orphan('originals/2026/10/0193a8f2-7c1e-7000-8000-000000000001.png');

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Orphans that would be deleted', $tester->getDisplay());
        self::assertStringContainsString('Dry run finished; nothing was changed.', $tester->getDisplay());
        self::assertFileExists($orphan);
    }

    public function testAnEmptyLibraryKeepsItsOrphansAndSaysWhy(): void
    {
        $this->orphan('originals/2026/10/0193a8f2-7c1e-7000-8000-000000000001.png');

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('the media library is empty', $tester->getDisplay());
    }

    public function testFilesOutsideTheLayoutAreListed(): void
    {
        MediaFactory::createOne();
        $this->orphan('notes.txt');

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('notes.txt', $tester->getDisplay());
    }

    public function testFailsWhileABackupHoldsTheFiles(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock(MediaStorageMigrator::LOCK, null);
        self::assertTrue($lock->acquire());

        try {
            $tester = $this->tester();
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('A backup, restore or media task is running.', $tester->getDisplay());
        } finally {
            $lock->release();
        }
    }

    /** A file below the storage root, last changed three days ago. */
    private function orphan(string $path): string
    {
        $absolute = self::getContainer()->get(MediaStorage::class)->root().'/'.$path;
        new Filesystem()->dumpFile($absolute, 'x');
        touch($absolute, time() - 3 * 86400);

        return $absolute;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:media:check'));
    }
}
