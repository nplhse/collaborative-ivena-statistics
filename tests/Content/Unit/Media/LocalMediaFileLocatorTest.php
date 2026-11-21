<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Media\LocalMediaFileLocator;
use App\Content\Infrastructure\Media\MediaStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class LocalMediaFileLocatorTest extends TestCase
{
    private const string STORAGE_DIR = '/var/project/var/media';
    private const string LEGACY_DIR = '/var/project/public/uploads/media';
    private const string KEY = 'originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png';

    public function testStoredMediaResolvesToTheStorage(): void
    {
        $media = new Media();
        $media->attachStoredFile(self::KEY, 68, str_repeat('a', 64), new \DateTimeImmutable('2026-11-21 10:00'));

        self::assertSame(self::STORAGE_DIR.'/'.self::KEY, $this->locator()->resolveAbsolutePath($media));
    }

    public function testMediaNotYetMovedResolvesToTheOldUploadDirectory(): void
    {
        $media = new Media();
        $media->setFilename('photo.png');

        self::assertSame(self::LEGACY_DIR.'/photo.png', $this->locator()->resolveAbsolutePath($media));
    }

    public function testMediaWithoutFileResolvesToNull(): void
    {
        self::assertNull($this->locator()->resolveAbsolutePath(new Media()));
    }

    public function testLegacyNamesThatLeaveTheDirectoryAreRefused(): void
    {
        self::assertNull($this->locator()->resolveLegacyPath('../.env.local'));
        self::assertNull($this->locator()->resolveLegacyPath('sub/photo.png'));
        self::assertNull($this->locator()->resolveLegacyPath('..'));
        self::assertNull($this->locator()->resolveLegacyPath(''));
    }

    public function testInvalidStorageKeysResolveToNull(): void
    {
        self::assertNull($this->locator()->resolveAbsolutePathFromStorageKey('originals/../../.env.local'));
        self::assertSame(self::STORAGE_DIR.'/'.self::KEY, $this->locator()->resolveAbsolutePathFromStorageKey(self::KEY));
    }

    private function locator(): LocalMediaFileLocator
    {
        return new LocalMediaFileLocator(new MediaStorage(self::STORAGE_DIR, new MockClock()), self::LEGACY_DIR);
    }
}
