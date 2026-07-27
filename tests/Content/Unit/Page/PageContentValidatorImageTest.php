<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentValidator;
use App\Content\Domain\Entity\Media;

final class PageContentValidatorImageTest extends PageContentValidatorTestCase
{
    public function testImageFloatRequiresNonFullWidth(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'size' => 'lg',
                    'float' => 'left',
                ],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('non-full-width', implode(' ', $errors));
    }

    public function testAutoImageSizeIsValid(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'size' => 'auto',
                    'float' => 'none',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageWithoutExplicitSizeDefaultsToAutoViaLegacyPreset(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'float' => 'none',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageWithUnknownWidthPresetDefaultsToAuto(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'widthPreset' => 'full',
                    'float' => 'none',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageRejectsInvalidSizeAndFloat(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'size' => 'xl',
                    'float' => 'center',
                ],
            ],
        ]);

        self::assertCount(2, $errors);
        self::assertStringContainsString('invalid image size', implode(' ', $errors));
        self::assertStringContainsString('invalid float option', implode(' ', $errors));
    }

    public function testImageAcceptsLegacyWidthPresetForSize(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'widthPreset' => 'md',
                    'float' => 'none',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageAcceptsLegacySmallWidthPresetForSize(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'widthPreset' => 'sm',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageAcceptsLegacyLargeWidthPresetForSize(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'widthPreset' => 'lg',
                    'float' => 'none',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageAcceptsMediaEntityReference(): void
    {
        $validator = new PageContentValidator($this->translator());
        $media = $this->createMock(Media::class);
        $media->method('getId')->willReturn(7);

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'mediaId' => $media,
                    'alt' => 'Alt',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageAcceptsStringMediaId(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'mediaId' => '42',
                    'alt' => 'Alt',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    public function testImageAcceptsNumericMediaId(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'image',
                'data' => [
                    'mediaId' => 42,
                    'alt' => 'Alt',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
