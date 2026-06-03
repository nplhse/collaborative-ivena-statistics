<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Media;

use App\Content\Application\Media\MediaImageFigureHtmlBuilder;
use PHPUnit\Framework\TestCase;

final class MediaImageFigureHtmlBuilderTest extends TestCase
{
    public function testBuildsFullWidthFigureByDefault(): void
    {
        $html = $this->builder()->build('/uploads/media/photo.webp', 'A photo');

        self::assertStringContainsString('<figure class="card card-sm page-content-image page-content-image--size-lg mb-0">', $html);
        self::assertStringContainsString('data-fslightbox="content-gallery"', $html);
        self::assertStringContainsString('<img class="card-img-top" src="/uploads/media/photo.webp" alt="A photo" loading="lazy">', $html);
    }

    public function testBuildsFloatedMediumFigure(): void
    {
        $html = $this->builder()->build('/uploads/media/photo.webp', 'A photo', 'md', 'left');

        self::assertStringContainsString('page-content-image--size-md', $html);
        self::assertStringContainsString('page-content-image--float-left', $html);
        self::assertStringContainsString('float-start', $html);
    }

    public function testIgnoresInvalidSizeAndFloat(): void
    {
        $html = $this->builder()->build('/uploads/media/photo.webp', 'A photo', 'xl', 'center');

        self::assertStringContainsString('page-content-image--size-lg', $html);
        self::assertStringNotContainsString('float-start', $html);
        self::assertStringNotContainsString('float-end', $html);
    }

    private function builder(): MediaImageFigureHtmlBuilder
    {
        return new MediaImageFigureHtmlBuilder();
    }
}
