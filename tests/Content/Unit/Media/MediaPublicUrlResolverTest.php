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

    public function testMediaWithoutFileHasNoUrl(): void
    {
        self::assertSame('', new MediaPublicUrlResolver()->resolve(new Media()));
    }

    public function testStorageKeyIsReadBackFromItsUrl(): void
    {
        $resolver = new MediaPublicUrlResolver();

        self::assertSame(self::KEY, $resolver->storageKeyFromUrl('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'));
        self::assertSame(self::KEY, $resolver->storageKeyFromUrl('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png?v=2'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignUrls(): iterable
    {
        yield 'other site' => ['https://example.org/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.png'];
        yield 'protocol-relative' => ['//example.org/uploads/media/photo.png'];
        yield 'not a key' => ['/media/2026/11/../../.env'];
        yield 'old upload directory' => ['/uploads/media/photo.png'];
        yield 'empty' => [''];
    }

    #[DataProvider('foreignUrls')]
    public function testForeignUrlsAreNotMedia(string $url): void
    {
        $resolver = new MediaPublicUrlResolver();

        self::assertNull($resolver->storageKeyFromUrl($url));
    }
}
