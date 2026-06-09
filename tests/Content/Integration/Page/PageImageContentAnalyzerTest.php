<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageImageContentAnalyzer;
use App\Content\Infrastructure\Factory\MediaFactory;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Path;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageImageContentAnalyzerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testRecommendsMigratingSmallFullWidthImageToAuto(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'small-image.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-small-lg',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/small-image.png',
                        'alt' => 'Small',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('Migrate size lg to auto', $findings[0]->recommendation);
        self::assertSame('ok', $findings[0]->status);
        self::assertSame(605, $findings[0]->imageWidth);
    }

    public function testAutoSizeImageNeedsNoChange(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'auto-image.png',
            'width' => 605,
            'height' => 400,
        ])->_real();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-auto',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/auto-image.png',
                        'alt' => 'Auto',
                        'size' => 'auto',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('No change', $findings[0]->recommendation);
    }

    public function testMissingLocalFileIsReported(): void
    {
        self::bootKernel();

        $media = MediaFactory::createOne([
            'filename' => 'missing-on-disk.png',
            'width' => 400,
            'height' => 300,
        ])->_real();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $path = Path::join($projectDir, 'public', 'uploads', 'media', 'missing-on-disk.png');
        if (is_file($path)) {
            unlink($path);
        }

        $page = PageFactory::createOne([
            'slug' => 'analyzer-missing-file',
            'content' => [
                [
                    'type' => 'image',
                    'enabled' => true,
                    'data' => [
                        'mediaId' => $media->getId(),
                        'src' => '/uploads/media/missing-on-disk.png',
                        'alt' => 'Missing',
                        'size' => 'lg',
                        'float' => 'none',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertSame('missing_file', $findings[0]->status);
        self::assertSame('Sync media files locally', $findings[0]->recommendation);
    }

    public function testDetectsRichtextSnippetWithLegacyLargeSizeClass(): void
    {
        self::bootKernel();

        $page = PageFactory::createOne([
            'slug' => 'analyzer-richtext-snippet',
            'content' => [
                [
                    'type' => 'richtext',
                    'enabled' => true,
                    'data' => [
                        'html' => '<figure class="page-content-image page-content-image--size-lg"><img src="/uploads/media/snippet.png" alt="Snippet"></figure>',
                    ],
                ],
            ],
        ])->_real();

        $findings = $this->analyzer()->analyze($page->getId());

        self::assertCount(1, $findings);
        self::assertStringContainsString('richtext', $findings[0]->blockType);
        self::assertSame('lg', $findings[0]->currentSize);
    }

    private function analyzer(): PageImageContentAnalyzer
    {
        return self::getContainer()->get(PageImageContentAnalyzer::class);
    }
}
