<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Media;

use App\Content\Infrastructure\Media\MediaStorage;
use App\Content\Infrastructure\Media\MediaStorageException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class MediaStorageTest extends TestCase
{
    private string $root;
    private string $source;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/media-storage-test-'.bin2hex(random_bytes(4));
        $this->source = $this->root.'-source.png';
        file_put_contents($this->source, 'hello media');
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->root, $this->source]);
    }

    public function testStoresTheFileInTheMonthOfTheUploadWithSizeAndChecksum(): void
    {
        $stored = $this->storage('2026-11-21 10:00')->store($this->source, 'PNG');

        self::assertMatchesRegularExpression('#^originals/2026/11/[0-9a-f-]{36}\.png$#', $stored->storageKey);
        self::assertSame(11, $stored->size);
        self::assertSame(hash('sha256', 'hello media'), $stored->checksumSha256);
        self::assertSame('hello media', file_get_contents($this->root.'/'.$stored->storageKey));
        self::assertSame([], glob($this->root.'/.tmp/*'), 'no partial file is left behind');
    }

    public function testAnExplicitDateChoosesTheMonthDirectory(): void
    {
        $stored = $this->storage('2026-11-21 10:00')->store($this->source, 'png', new \DateTimeImmutable('2024-02-03 08:00'));

        self::assertStringStartsWith('originals/2024/02/', $stored->storageKey);
    }

    public function testTwoStoresOfTheSameFileGetTwoKeys(): void
    {
        $storage = $this->storage('2026-11-21 10:00');

        self::assertNotSame($storage->store($this->source, 'png')->storageKey, $storage->store($this->source, 'png')->storageKey);
    }

    public function testExistsFindsTheStoredFile(): void
    {
        $storage = $this->storage('2026-11-21 10:00');
        $key = $storage->store($this->source, 'png')->storageKey;

        self::assertTrue($storage->exists($key));
        self::assertFalse($storage->exists('originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'));
    }

    public function testDeleteRemovesTheFileAndItsVariants(): void
    {
        $storage = $this->storage('2026-11-21 10:00');
        $key = $storage->store($this->source, 'png')->storageKey;
        $variants = $storage->variantDirectory($key);
        new Filesystem()->dumpFile($variants.'/thumb-320.webp', 'thumb');

        $storage->delete($key);

        self::assertFalse($storage->exists($key));
        self::assertDirectoryDoesNotExist($variants);
    }

    public function testVariantDirectoryMirrorsTheKey(): void
    {
        $key = 'originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png';

        self::assertSame($this->root.'/variants/2026/11/0193a8f2-7c1e-7000-8000-000000000001', $this->storage('2026-11-21')->variantDirectory($key));
    }

    public function testKeysOutsideTheLayoutAreRefused(): void
    {
        self::assertFalse(MediaStorage::isValidKey('originals/2026/11/../../../.env.local'));
        self::assertFalse(MediaStorage::isValidKey('variants/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'));
        self::assertFalse(MediaStorage::isValidKey('photo.png'));

        $this->expectException(MediaStorageException::class);
        $this->storage('2026-11-21')->absolutePath('../../etc/passwd');
    }

    public function testAMissingSourceIsReportedAndLeavesNothingBehind(): void
    {
        $storage = $this->storage('2026-11-21');

        try {
            $storage->store($this->root.'/missing.png', 'png');
            self::fail('A missing source must be refused.');
        } catch (MediaStorageException) {
        }

        self::assertSame([], glob($this->root.'/originals/*/*/*') ?: []);
        self::assertSame([], glob($this->root.'/.tmp/*') ?: []);
    }

    public function testAnInvalidExtensionIsRefused(): void
    {
        $this->expectException(MediaStorageException::class);

        $this->storage('2026-11-21')->store($this->source, '../php');
    }

    private function storage(string $now): MediaStorage
    {
        return new MediaStorage($this->root, new MockClock(new \DateTimeImmutable($now)));
    }
}
