<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Application\Media\MediaImageFigureHtmlBuilder;
use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Application\Media\MediaSnippetGenerator;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use PHPUnit\Framework\TestCase;

final class MediaSnippetGeneratorTest extends TestCase
{
    public function testImageSnippetContainsPublicUrlAndAlt(): void
    {
        $media = $this->imageMedia('0193a8f2-7c1e-7000-8000-000000000001.webp', 'A photo');

        $html = $this->generator()->generateHtml($media);

        self::assertSame(
            new MediaImageFigureHtmlBuilder()->build('/media/2026/11/0193a8f2-7c1e-7000-8000-000000000001.webp', 'A photo'),
            $html,
        );
    }

    public function testImageFigureSnippetSupportsCustomLayout(): void
    {
        $media = $this->imageMedia('0193a8f2-7c1e-7000-8000-000000000001.webp', 'A photo');

        $html = $this->generator()->generateImageFigureHtml($media, 'md', 'right');

        self::assertStringContainsString('page-content-image--size-md', $html);
        self::assertStringContainsString('page-content-image--float-right', $html);
        self::assertStringContainsString('float-end', $html);
    }

    public function testPdfSnippetContainsNoopener(): void
    {
        $media = $this->pdfMedia('0193a8f2-7c1e-7000-8000-000000000002.pdf', 'Annual report');

        $html = $this->generator()->generateHtml($media);

        self::assertStringContainsString('href="/media/2026/11/0193a8f2-7c1e-7000-8000-000000000002.pdf"', $html);
        self::assertStringContainsString('rel="noopener"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('Annual report', $html);
    }

    private function generator(): MediaSnippetGenerator
    {
        return new MediaSnippetGenerator(new MediaPublicUrlResolver(), new MediaImageFigureHtmlBuilder());
    }

    private function imageMedia(string $name, string $alt): Media
    {
        $media = new Media();
        $media->attachStoredFile('originals/2026/11/'.$name, 1, str_repeat('a', 64), new \DateTimeImmutable('2026-11-21'));
        $media->setType(MediaType::IMAGE);
        $media->setAltText($alt);

        return $media;
    }

    private function pdfMedia(string $name, string $title): Media
    {
        $media = new Media();
        $media->attachStoredFile('originals/2026/11/'.$name, 1, str_repeat('a', 64), new \DateTimeImmutable('2026-11-21'));
        $media->setType(MediaType::PDF);
        $media->setTitle($title);

        return $media;
    }
}
