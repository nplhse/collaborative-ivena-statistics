<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Domain\Entity\Media;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaPublicUrlResolverTest extends TestCase
{
    private const string KEY = 'originals/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png';

    public function testStoredMediaGetsAMediaUrl(): void
    {
        $media = new Media();
        $media->attachStoredFile(self::KEY, 1, str_repeat('a', 64), new \DateTimeImmutable('2026-11-21'));

        self::assertSame('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png', new MediaPublicUrlResolver()->resolve($media));
    }

    public function testMediaNotYetMovedKeepsItsOldUrl(): void
    {
        $media = new Media();
        $media->setFilename('photo-abc.png');

        self::assertSame('/uploads/media/photo-abc.png', new MediaPublicUrlResolver()->resolve($media));
    }

    public function testMovingMediaKeepsTheOldNameForOldUrls(): void
    {
        $media = new Media();
        $media->setFilename('photo-abc.png');
        $media->attachStoredFile(self::KEY, 1, str_repeat('a', 64), new \DateTimeImmutable('2026-11-21'));

        self::assertNull($media->getFilename());
        self::assertSame('photo-abc.png', $media->getLegacyFilename());
    }

    public function testMediaWithoutFileHasNoUrl(): void
    {
        self::assertSame('', new MediaPublicUrlResolver()->resolve(new Media()));
    }

    public function testStorageKeyIsReadBackFromItsUrl(): void
    {
        $resolver = new MediaPublicUrlResolver();

        self::assertSame(self::KEY, $resolver->storageKeyFromUrl('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'));
        self::assertSame(self::KEY, $resolver->storageKeyFromUrl('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png?v=2'));
        self::assertNull($resolver->legacyFilenameFromUrl('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'));
    }

    public function testOldUrlGivesTheOldFileName(): void
    {
        $resolver = new MediaPublicUrlResolver();

        self::assertSame('photo abc.png', $resolver->legacyFilenameFromUrl('/uploads/media/photo%20abc.png'));
        self::assertNull($resolver->storageKeyFromUrl('/uploads/media/photo.png'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignUrls(): iterable
    {
        yield 'other site' => ['https://example.org/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'];
        yield 'protocol-relative' => ['//example.org/uploads/media/photo.png'];
        yield 'not a key' => ['/media/2026/11/../../.env'];
        yield 'sub directory' => ['/uploads/media/sub/photo.png'];
        yield 'empty' => [''];
    }

    #[DataProvider('foreignUrls')]
    public function testForeignUrlsAreNotMedia(string $url): void
    {
        $resolver = new MediaPublicUrlResolver();

        self::assertNull($resolver->storageKeyFromUrl($url));
        self::assertNull($resolver->legacyFilenameFromUrl($url));
    }
}
