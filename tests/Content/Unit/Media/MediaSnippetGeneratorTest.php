<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Application\Media\MediaPublicUrlResolver;
use App\Content\Application\Media\MediaSnippetGenerator;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use PHPUnit\Framework\TestCase;

final class MediaSnippetGeneratorTest extends TestCase
{
    public function testImageSnippetContainsPublicUrlAndAlt(): void
    {
        $media = $this->imageMedia('photo.webp', 'A photo');

        $html = $this->generator()->generateHtml($media);

        self::assertSame('<img src="/uploads/media/photo.webp" alt="A photo">', $html);
    }

    public function testPdfSnippetContainsNoopener(): void
    {
        $media = $this->pdfMedia('doc.pdf', 'Annual report');

        $html = $this->generator()->generateHtml($media);

        self::assertStringContainsString('href="/uploads/media/doc.pdf"', $html);
        self::assertStringContainsString('rel="noopener"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('Annual report', $html);
    }

    private function generator(): MediaSnippetGenerator
    {
        return new MediaSnippetGenerator(new MediaPublicUrlResolver());
    }

    private function imageMedia(string $filename, string $alt): Media
    {
        $media = new Media();
        $media->setFilename($filename);
        $media->setType(MediaType::IMAGE);
        $media->setAltText($alt);

        return $media;
    }

    private function pdfMedia(string $filename, string $title): Media
    {
        $media = new Media();
        $media->setFilename($filename);
        $media->setType(MediaType::PDF);
        $media->setTitle($title);

        return $media;
    }
}
