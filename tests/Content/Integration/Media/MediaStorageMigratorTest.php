<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Media\MediaStorage;
use App\Content\Infrastructure\Media\MediaStorageMigrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Moving media from the old upload directory into the storage (ADR 039). Fixture: two media items in the old
 * directory (uploaded in March 2025 and November 2026), one whose file is gone, one already stored, and one stray
 * file without a media item.
 */
#[ResetDatabase]
final class MediaStorageMigratorTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private string $legacyDir;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->legacyDir = sys_get_temp_dir().'/media-legacy-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->legacyDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->legacyDir);
        parent::tearDown();
    }

    public function testCopiesEachFileIntoTheMonthOfItsUploadAndVerifiesIt(): void
    {
        [$march, $november] = $this->fixture();

        $result = $this->migrate(false);

        self::assertSame(2, $result->moved);
        self::assertSame(\strlen('march bytes') + \strlen('november bytes'), $result->movedBytes);
        self::assertSame(['gone.png'], $result->missing);
        self::assertSame([], $result->failed);
        self::assertSame(['stray.png'], $result->unlinked);

        $movedMarch = $this->reload($march);
        self::assertStringStartsWith('originals/2025/03/', (string) $movedMarch->getStorageKey());
        self::assertStringEndsWith('.png', (string) $movedMarch->getStorageKey());
        self::assertSame(hash('sha256', 'march bytes'), $movedMarch->getChecksumSha256());
        self::assertSame(\strlen('march bytes'), $movedMarch->getSize());
        self::assertSame('march.png', $movedMarch->getLegacyFilename());
        self::assertNull($movedMarch->getFilename());
        self::assertSame('march bytes', file_get_contents($this->storage()->absolutePath((string) $movedMarch->getStorageKey())));

        self::assertStringStartsWith('originals/2026/11/', (string) $this->reload($november)->getStorageKey());
        self::assertFileExists($this->legacyDir.'/march.png', 'the old directory stays as it is');
    }

    public function testASecondRunMovesNothing(): void
    {
        $this->fixture();
        $this->migrate(false);

        $result = $this->migrate(false);

        self::assertSame(0, $result->moved);
        self::assertSame(['gone.png'], $result->missing);
    }

    public function testDryRunChangesNothing(): void
    {
        [$march] = $this->fixture();

        $result = $this->migrate(true);

        self::assertSame(2, $result->moved);
        self::assertNull($this->reload($march)->getStorageKey());
        self::assertSame('march.png', $this->reload($march)->getFilename());
    }

    public function testFlatFilesInTheStorageRootAreMovedIntoTheLayout(): void
    {
        $root = $this->storage()->root();
        new Filesystem()->dumpFile($root.'/restored-flat.png', 'flat bytes');
        $this->mockTime('2025-01-15 12:00:00');
        $media = MediaFactory::createOne(['filename' => 'restored-flat.png']);

        $result = self::getContainer()->get(MediaStorageMigrator::class)->migrate($root, false, static function (): void {});

        self::assertSame(1, $result->moved);
        self::assertStringStartsWith('originals/2025/01/', (string) $this->reload($media)->getStorageKey());
        self::assertFileDoesNotExist($root.'/restored-flat.png', 'a flat file of an old backup is removed once it is moved');
    }

    /**
     * @return array{Media, Media}
     */
    private function fixture(): array
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->legacyDir.'/march.png', 'march bytes');
        $filesystem->dumpFile($this->legacyDir.'/november.png', 'november bytes');
        $filesystem->dumpFile($this->legacyDir.'/stray.png', 'stray bytes');
        $filesystem->dumpFile($this->legacyDir.'/.htaccess', 'Options -Indexes');

        $this->mockTime('2025-03-10 09:00:00');
        $march = $this->legacyMedia('march.png');
        $this->mockTime('2026-11-02 09:00:00');
        $november = $this->legacyMedia('november.png');
        MediaFactory::createOne(['filename' => 'gone.png']);
        unlink(self::getContainer()->getParameter('app.media_legacy_dir').'/gone.png');
        MediaFactory::createOne();

        return [$march, $november];
    }

    /** A media item whose file is only in the temporary old directory of this test. */
    private function legacyMedia(string $filename): Media
    {
        $media = MediaFactory::createOne(['filename' => $filename]);
        @unlink(self::getContainer()->getParameter('app.media_legacy_dir').'/'.$filename);

        return $media;
    }

    private function migrate(bool $dryRun): \App\Content\Application\Media\MediaStorageMigrationResult
    {
        return self::getContainer()->get(MediaStorageMigrator::class)->migrate($this->legacyDir, $dryRun, static function (): void {});
    }

    private function reload(Media $media): Media
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Media::class, $media->getId());
        self::assertInstanceOf(Media::class, $reloaded);

        return $reloaded;
    }

    private function storage(): MediaStorage
    {
        return self::getContainer()->get(MediaStorage::class);
    }
}
