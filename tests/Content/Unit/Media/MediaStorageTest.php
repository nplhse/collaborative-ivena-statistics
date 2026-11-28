<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Infrastructure\Media\MediaStorage;
use App\Content\Infrastructure\Media\MediaStorageException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        // One directory per process: ParaTest workers run in parallel, the tests of one process run one after another.
        $this->root = sys_get_temp_dir().'/media-storage-test-'.getmypid();
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
        $first = $storage->store($this->source, 'png');

        $second = $storage->store($this->source, 'png');

        self::assertNotSame($first->storageKey, $second->storageKey);
    }

    public function testTheStoredFileIsAtItsKey(): void
    {
        $storage = $this->storage('2026-11-21 10:00');
        $key = $storage->store($this->source, 'png')->storageKey;

        $path = $storage->absolutePath($key);

        self::assertTrue(is_file($path));
    }

    public function testAKeyWithoutAStoredFileLeadsToNoFile(): void
    {
        $storage = $this->storage('2026-11-21 10:00');
        $storage->store($this->source, 'png');

        $path = $storage->absolutePath('originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png');

        self::assertSame($this->root.'/originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png', $path);
        self::assertFalse(is_file($path));
    }

    public function testDeleteRemovesTheFileAndItsVariants(): void
    {
        $storage = $this->storage('2026-11-21 10:00');
        $key = $storage->store($this->source, 'png')->storageKey;
        $variants = $storage->variantDirectory($key);
        new Filesystem()->dumpFile($variants.'/thumb-320.webp', 'thumb');

        $storage->delete($key);

        self::assertFalse(is_file($storage->absolutePath($key)));
        self::assertDirectoryDoesNotExist($variants);
    }

    public function testVariantDirectoryMirrorsTheKey(): void
    {
        $key = 'originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png';

        $directory = $this->storage('2026-11-21')->variantDirectory($key);

        self::assertSame($this->root.'/variants/2026/11/0193a8f2-7c1e-7000-8000-000000000001', $directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysOutsideTheLayout(): iterable
    {
        yield 'path traversal' => ['originals/2026/11/../../../.env.local'];
        yield 'variant instead of original' => ['variants/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'];
        yield 'bare file name' => ['photo.png'];
    }

    #[DataProvider('keysOutsideTheLayout')]
    public function testKeysOutsideTheLayoutAreNotValid(string $key): void
    {
        $valid = MediaStorage::isValidKey($key);

        self::assertFalse($valid);
    }

    public function testAbsolutePathRefusesAKeyOutsideTheLayout(): void
    {
        $this->expectException(MediaStorageException::class);
        $this->expectExceptionMessage('"../../etc/passwd" is not a media storage key.');

        $this->storage('2026-11-21')->absolutePath('../../etc/passwd');
    }

    public function testVariantDirectoryRefusesAKeyOutsideTheLayout(): void
    {
        $this->expectException(MediaStorageException::class);
        $this->expectExceptionMessage('"originals/../../x.png" is not a media storage key.');

        $this->storage('2026-11-21')->variantDirectory('originals/../../x.png');
    }

    public function testDeleteRefusesAKeyOutsideTheLayout(): void
    {
        $this->expectException(MediaStorageException::class);

        $this->storage('2026-11-21')->delete('../../.env.local');
    }

    public function testAMissingSourceIsRefused(): void
    {
        $this->expectException(MediaStorageException::class);
        $this->expectExceptionMessage('Could not read the file missing.png.');

        $this->storage('2026-11-21')->store($this->root.'/missing.png', 'png');
    }

    public function testAMissingSourceLeavesNothingBehind(): void
    {
        $storage = $this->storage('2026-11-21');

        $this->failingStore($storage, $this->root.'/missing.png');

        self::assertSame([], glob($this->root.'/originals/*/*/*') ?: []);
        self::assertSame([], glob($this->root.'/.tmp/*') ?: []);
    }

    public function testAnInvalidExtensionIsRefused(): void
    {
        $this->expectException(MediaStorageException::class);

        $this->storage('2026-11-21')->store($this->source, '../php');
    }

    public function testAFailedMoveIntoTheMonthIsReported(): void
    {
        new Filesystem()->dumpFile($this->root.'/originals/2026/11', 'a file where the month directory belongs');

        $this->expectException(MediaStorageException::class);
        $this->expectExceptionMessage('Could not move the stored file');

        $this->storage('2026-11-21 10:00')->store($this->source, 'png');
    }

    public function testAFailedMoveIntoTheMonthLeavesNoPartialFile(): void
    {
        new Filesystem()->dumpFile($this->root.'/originals/2026/11', 'a file where the month directory belongs');

        $this->failingStore($this->storage('2026-11-21 10:00'), $this->source);

        self::assertSame([], glob($this->root.'/.tmp/*') ?: []);
    }

    public function testAStorageThatCannotPrepareItsTemporaryDirectoryReportsItAsAStorageError(): void
    {
        new Filesystem()->dumpFile($this->root.'/.tmp', 'a file where the temporary directory belongs');

        $this->expectException(MediaStorageException::class);
        $this->expectExceptionMessage('Could not prepare the media storage for writing.');

        $this->storage('2026-11-21 10:00')->store($this->source, 'png');
    }

    private function storage(string $now): MediaStorage
    {
        return new MediaStorage($this->root, new MockClock(new \DateTimeImmutable($now)));
    }

    /**
     * Runs a store that must fail, for tests that check what the failure leaves behind; that the failure is reported
     * is covered by the tests with expectException().
     */
    private function failingStore(MediaStorage $storage, string $source): void
    {
        try {
            $storage->store($source, 'png');
        } catch (MediaStorageException) {
            return;
        }

        self::fail('The store was expected to fail.');
    }
}
