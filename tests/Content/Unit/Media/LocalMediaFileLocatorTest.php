<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Media\LocalMediaFileLocator;
use PHPUnit\Framework\TestCase;

final class LocalMediaFileLocatorTest extends TestCase
{
    private const string UPLOAD_DIR = '/var/project/public/uploads/media';

    public function testResolveAbsolutePathFromMediaFilename(): void
    {
        $media = new Media();
        $media->setFilename('photo.png');

        self::assertSame(
            self::UPLOAD_DIR.'/photo.png',
            $this->locator()->resolveAbsolutePath($media),
        );
    }

    public function testResolveAbsolutePathReturnsNullWhenFilenameMissing(): void
    {
        self::assertNull($this->locator()->resolveAbsolutePath(new Media()));
    }

    public function testResolveFilenameFromPublicSrc(): void
    {
        self::assertSame(
            'photo.png',
            $this->locator()->resolveFilenameFromPublicSrc('/uploads/media/photo.png'),
        );
    }

    public function testResolveFilenameFromPublicSrcReturnsNullForExternalPath(): void
    {
        self::assertNull($this->locator()->resolveFilenameFromPublicSrc('https://example.org/photo.png'));
    }

    private function locator(): LocalMediaFileLocator
    {
        return new LocalMediaFileLocator(self::UPLOAD_DIR);
    }
}
