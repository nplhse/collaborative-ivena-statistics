<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentBlockDataNormalizer;
use PHPUnit\Framework\TestCase;

final class PageContentBlockDataNormalizerTest extends TestCase
{
    public function testStripsFieldsNotMatchingBlockType(): void
    {
        $sut = new PageContentBlockDataNormalizer();

        $normalized = $sut->normalize([
            [
                'type' => 'image',
                'enabled' => true,
                'data' => [
                    'html' => '<p>legacy</p>',
                    'alt' => 'Alt text',
                    'src' => '/uploads/media/x.png',
                ],
            ],
        ]);

        self::assertSame([
            [
                'type' => 'image',
                'enabled' => true,
                'data' => [
                    'alt' => 'Alt text',
                    'src' => '/uploads/media/x.png',
                    'size' => 'lg',
                    'float' => 'none',
                ],
            ],
        ], $normalized);
    }

    public function testNormalizesHeadlineBlockFields(): void
    {
        $sut = new PageContentBlockDataNormalizer();

        $normalized = $sut->normalize([
            [
                'type' => 'headline',
                'data' => [
                    'text' => 'Section title',
                    'level' => 'h3',
                    'align' => 'center',
                    'spacingBefore' => 'sm',
                    'spacingAfter' => 'lg',
                    'html' => '<p>ignored</p>',
                ],
            ],
        ]);

        self::assertSame([
            [
                'type' => 'headline',
                'data' => [
                    'text' => 'Section title',
                    'level' => 'h3',
                    'align' => 'center',
                    'spacingBefore' => 'sm',
                    'spacingAfter' => 'lg',
                ],
            ],
        ], $normalized);
    }

    public function testNormalizesAccordionItems(): void
    {
        $sut = new PageContentBlockDataNormalizer();

        $normalized = $sut->normalize([
            [
                'type' => 'accordion',
                'data' => [
                    'items' => [
                        [
                            'title' => 'Question',
                            'html' => '<p>Answer</p>',
                            'openByDefault' => true,
                            'extra' => 'ignored',
                        ],
                        'invalid-item',
                    ],
                ],
            ],
        ]);

        self::assertSame([
            [
                'type' => 'accordion',
                'data' => [
                    'items' => [
                        [
                            'title' => 'Question',
                            'html' => '<p>Answer</p>',
                            'openByDefault' => true,
                        ],
                    ],
                ],
            ],
        ], $normalized);
    }

    public function testNormalizesImageSizeAndFloat(): void
    {
        $sut = new PageContentBlockDataNormalizer();

        $normalized = $sut->normalize([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'size' => 'md',
                    'float' => 'right',
                    'widthMode' => 'percent',
                    'widthPercent' => 50,
                ],
            ],
        ]);

        self::assertSame([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'size' => 'md',
                    'float' => 'right',
                ],
            ],
        ], $normalized);
    }

    public function testNormalizesLegacyImageWidthPresetToSize(): void
    {
        $sut = new PageContentBlockDataNormalizer();

        $normalized = $sut->normalize([
            [
                'type' => 'image',
                'data' => [
                    'src' => '/img.jpg',
                    'alt' => 'Alt',
                    'widthPreset' => 'sm',
                ],
            ],
        ]);

        self::assertSame('sm', $normalized[0]['data']['size']);
    }
}
