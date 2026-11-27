<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Domain\Entity\Media;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\Infrastructure\Media\MediaStorage;
use Symfony\Component\Filesystem\Filesystem;

final class PageImageContentAnalyzerImageBlockTest extends PageImageContentAnalyzerTestCase
{
    public function testRecommendsMigratingSmallFullWidthImageToAuto(): void
    {
        $media = MediaFactory::createOne([
            'width' => 605,
            'height' => 400,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-small-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Small',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('Migrate size lg to auto', $findings[0]->recommendation);
        self::assertSame('ok', $findings[0]->status);
        self::assertSame(605, $findings[0]->imageWidth);
    }

    public function testAutoSizeImageNeedsNoChange(): void
    {
        $media = MediaFactory::createOne([
            'width' => 605,
            'height' => 400,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-auto',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Auto',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('No change', $findings[0]->recommendation);
    }

    public function testMissingLocalFileIsReported(): void
    {
        $media = MediaFactory::createOne([
            'width' => 400,
            'height' => 300,
        ]);

        unlink($this->storedPath($media));

        $page = PageFactory::createOne([
            'slug' => 'analyzer-missing-file',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Missing',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('missing_file', $findings[0]->status);
        self::assertSame('Sync media files locally', $findings[0]->recommendation);
    }

    public function testAnalyzeAllPagesWhenNoPageIdFilter(): void
    {
        $this->createImagePage('analyzer-all-1');
        $this->createImagePage('analyzer-all-2');

        $findings = $this->analyzer()->analyze();

        self::assertGreaterThanOrEqual(2, count($findings));
    }

    public function testReturnsEmptyFindingsForUnknownPageId(): void
    {
        self::assertSame([], $this->analyzer()->analyze(999_999));
    }

    public function testReportsDimensionsUnknownWhenDatabaseMetadataMissing(): void
    {
        $media = MediaFactory::createOne([
            'width' => null,
            'height' => null,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-dims-unknown',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Unknown dims',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('ok', $findings[0]->status);
        self::assertSame(1, $findings[0]->imageWidth);
        self::assertSame(1, $findings[0]->imageHeight);
    }

    public function testReportsExternalSrcForNonMediaPath(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'analyzer-external-src',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'src' => 'https://example.com/photo.png',
                        'alt' => 'External',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('external_src', $findings[0]->status);
    }

    public function testReportsMissingSrcWhenImageBlockHasNoMediaOrSrc(): void
    {
        $page = PageFactory::createOne([
            'slug' => 'analyzer-missing-src',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'alt' => 'No source',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('missing_src', $findings[0]->status);
    }

    public function testResolvesMediaByStringMediaId(): void
    {
        $media = MediaFactory::createOne([
            'width' => 320,
            'height' => 200,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-string-media-id',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => (string) $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'String id',
                        'size' => 'sm',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame($media->getId(), $findings[0]->mediaId);
        self::assertSame('No change', $findings[0]->recommendation);
    }

    public function testResolvesLegacyWidthPresetToSize(): void
    {
        $media = MediaFactory::createOne([
            'width' => 400,
            'height' => 300,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-legacy-preset',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Preset',
                        'widthPreset' => 'md',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('md', $findings[0]->currentSize);
    }

    public function testResolvesSmallAndLargeLegacyWidthPresets(): void
    {
        $small = MediaFactory::createOne([
            'width' => 200,
            'height' => 100,
        ]);
        $large = MediaFactory::createOne([
            'width' => 900,
            'height' => 600,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-preset-sm-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $small->getId(),
                        'src' => $this->url($small),
                        'alt' => 'Small preset',
                        'widthPreset' => 'sm',
                        'float' => 'none',
                    ],
                ],
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $large->getId(),
                        'src' => $this->url($large),
                        'alt' => 'Large preset',
                        'widthPreset' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(2, $findings);
        self::assertSame('sm', $findings[0]->currentSize);
        self::assertSame('lg', $findings[1]->currentSize);
        self::assertSame('No change', $findings[0]->recommendation);
        self::assertSame('Review layout', $findings[1]->recommendation);
    }

    public function testLargeFullWidthImageRecommendsReviewLayout(): void
    {
        $media = MediaFactory::createOne([
            'width' => 1200,
            'height' => 800,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-wide-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Wide',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('Review layout', $findings[0]->recommendation);
    }

    public function testResolvesMediaBySrcStorageKeyWhenMediaIdMissing(): void
    {
        $media = MediaFactory::createOne([
            'width' => 200,
            'height' => 100,
        ]);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-src-lookup',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'src' => $this->url($media),
                        'alt' => 'Lookup',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame($media->getId(), $findings[0]->mediaId);
    }

    public function testResolvesSrcOnlyImageWhenNoMediaRecordExists(): void
    {
        $source = new Filesystem()->tempnam(sys_get_temp_dir(), 'orphan-src-only-');
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $storageKey = self::getContainer()->get(MediaStorage::class)->store($source, 'png')->storageKey;
        unlink($source);

        $page = PageFactory::createOne([
            'slug' => 'analyzer-orphan-src',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'src' => '/media/'.substr($storageKey, \strlen('originals/')),
                        'alt' => 'Orphan',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertNull($findings[0]->mediaId);
        self::assertSame($storageKey, $findings[0]->filename);
        self::assertSame('ok', $findings[0]->status);
        self::assertSame(1, $findings[0]->imageWidth);
    }

    public function testUnreadableLocalFileReportsDimensionsUnknown(): void
    {
        $media = MediaFactory::createOne([
            'width' => null,
            'height' => null,
        ]);

        file_put_contents($this->storedPath($media), 'not-an-image');

        $page = PageFactory::createOne([
            'slug' => 'analyzer-unreadable-file',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Unreadable',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('dimensions_unknown', $findings[0]->status);
        self::assertSame('Backfill image dimensions', $findings[0]->recommendation);
    }

    private function createImagePage(string $slug): void
    {
        $media = MediaFactory::createOne([
            'width' => 100,
            'height' => 100,
        ]);

        PageFactory::createOne([
            'slug' => $slug,
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => $this->url($media),
                        'alt' => 'Image',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ]);
    }

    private function url(Media $media): string
    {
        return new MediaPublicUrlResolver()->resolve($media);
    }

    private function storedPath(Media $media): string
    {
        return self::getContainer()->get(MediaStorage::class)->absolutePath((string) $media->getStorageKey());
    }
}
