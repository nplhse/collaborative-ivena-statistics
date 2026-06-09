<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentValidator;
use App\Content\Domain\Entity\Media;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PageContentValidatorTest extends TestCase
{
    public function testValidContentProducesNoErrors(): void
    {
        $validator = new PageContentValidator($this->translator());

        $content = [
            ['type' => 'richtext', 'enabled' => true, 'data' => ['html' => '<p>Hallo</p>']],
            ['type' => 'image', 'data' => ['src' => '/img.jpg', 'alt' => 'Alt']],
            ['type' => 'cta', 'data' => ['headline' => 'Mehr', 'buttonLabel' => 'Los', 'buttonUrl' => '/x']],
            ['type' => 'quote', 'data' => ['text' => 'Zitat']],
            ['type' => 'headline', 'data' => ['text' => 'Title']],
            ['type' => 'highlight', 'data' => ['html' => '<p>Info</p>']],
            [
                'type' => 'accordion',
                'data' => [
                    'items' => [
                        ['title' => 'Q', 'html' => '<p>A</p>'],
                    ],
                ],
            ],
        ];

        self::assertSame([], $validator->validate($content));
    }

    public function testInvalidContentReturnsReadableErrors(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            ['type' => 'unknown', 'data' => []],
            ['type' => 'image', 'data' => ['src' => '']],
            ['type' => 'richtext', 'data' => []],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('unknown block type "unknown"', implode(' ', $errors));
        self::assertStringContainsString('image src or media required', implode(' ', $errors));
        self::assertStringContainsString('data.html is required', implode(' ', $errors));
    }

    public function testAccordionRequiresAtLeastOneItem(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            ['type' => 'accordion', 'data' => ['items' => []]],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('accordion item', implode(' ', $errors));
    }

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

    public function testHighlightCustomIconRequiresIconName(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'highlight',
                'data' => [
                    'html' => '<p>Info</p>',
                    'iconMode' => 'custom',
                ],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('custom icon', implode(' ', $errors));
    }

    public function testNonArrayContentReturnsSingleError(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate('not-a-list');

        self::assertSame(['Content must be a list of blocks.'], $errors);
    }

    public function testScalarBlockIsRejected(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            'scalar-block',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Block 1', $errors[0]);
        self::assertStringContainsString('must be an object.', $errors[0]);
    }

    public function testMissingBlockTypeIsRejected(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            ['data' => ['html' => '<p>x</p>']],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('field "type" is required', $errors[0]);
    }

    public function testBlockDataMustBeObject(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            ['type' => 'richtext', 'data' => 'invalid'],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('field "data" must be an object', $errors[0]);
    }

    public function testEnabledMustBeBoolean(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            ['type' => 'richtext', 'enabled' => 'yes', 'data' => ['html' => '<p>x</p>']],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('field "enabled" must be true or false', $errors[0]);
    }

    public function testHeadlineRejectsInvalidOptions(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'headline',
                'data' => [
                    'text' => 'Title',
                    'level' => 'h5',
                    'align' => 'justify',
                    'spacingBefore' => 'xl',
                ],
            ],
        ]);

        self::assertCount(3, $errors);
        self::assertStringContainsString('invalid headline level', implode(' ', $errors));
        self::assertStringContainsString('invalid headline alignment', implode(' ', $errors));
        self::assertStringContainsString('invalid spacingBefore', implode(' ', $errors));
    }

    public function testHighlightRejectsInvalidVariantAndIconMode(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'highlight',
                'data' => [
                    'html' => '<p>Info</p>',
                    'variant' => 'purple',
                    'iconMode' => 'emoji',
                ],
            ],
        ]);

        self::assertCount(2, $errors);
        self::assertStringContainsString('invalid highlight variant', implode(' ', $errors));
        self::assertStringContainsString('invalid icon mode', implode(' ', $errors));
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

    public function testCtaRequiresButtonLabel(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'cta',
                'data' => [
                    'headline' => 'More',
                    'buttonUrl' => '/go',
                ],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('data.buttonLabel is required', $errors[0]);
    }

    public function testHeadlineRequiresText(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'headline',
                'data' => ['level' => 'h2'],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('data.text is required', $errors[0]);
    }

    public function testHighlightRequiresHtml(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'highlight',
                'data' => ['variant' => 'info'],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('data.html is required', $errors[0]);
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

    public function testCtaWithMediaLinkRequiresMediaReference(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'cta',
                'data' => [
                    'headline' => 'Download',
                    'buttonLabel' => 'PDF',
                    'linkType' => 'media',
                ],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('media library PDF', $errors[0]);
    }

    public function testCtaWithUrlLinkRequiresButtonUrl(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'cta',
                'data' => [
                    'headline' => 'More',
                    'buttonLabel' => 'Go',
                    'linkType' => 'url',
                ],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('data.buttonUrl is required', $errors[0]);
    }

    public function testAccordionItemMustBeObject(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'accordion',
                'data' => [
                    'items' => [
                        'invalid-item',
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('must be an object', $errors[0]);
    }

    public function testAccordionItemRequiresTitleAndHtml(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            [
                'type' => 'accordion',
                'data' => [
                    'items' => [
                        ['title' => '', 'html' => ''],
                    ],
                ],
            ],
        ]);

        self::assertCount(2, $errors);
        self::assertStringContainsString('data.title is required', implode(' ', $errors));
        self::assertStringContainsString('data.html is required', implode(' ', $errors));
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

    public function testAssertValidThrowsWhenInvalid(): void
    {
        $validator = new PageContentValidator($this->translator());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field "type" is required');

        $validator->assertValid([
            ['data' => []],
        ]);
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $id = (string) $id;

                $messages = [
                    'page.validation.content_must_be_array' => 'Content must be a list of blocks.',
                    'page.validation.block_must_be_object' => 'must be an object.',
                    'page.validation.block_type_required' => 'field "type" is required.',
                    'page.validation.block_unknown_type' => 'unknown block type "{type}".',
                    'page.validation.block_data_must_be_object' => 'field "data" must be an object.',
                    'page.validation.block_enabled_must_be_bool' => 'field "enabled" must be true or false.',
                    'page.validation.block_required_field' => 'data.{field} is required.',
                    'page.validation.image_src_or_media_required' => 'image src or media required.',
                    'page.validation.image_invalid_size' => 'invalid image size.',
                    'page.validation.image_invalid_float' => 'invalid float option.',
                    'page.validation.accordion_items_required' => 'At least one accordion item is required.',
                    'page.validation.accordion_item_must_be_object' => 'must be an object.',
                    'page.validation.image_float_requires_non_full_width' => 'Text wrap requires a non-full-width image.',
                    'page.validation.highlight_icon_required' => 'A custom icon is required when icon mode is custom.',
                    'page.validation.headline_invalid_level' => 'invalid headline level.',
                    'page.validation.headline_invalid_align' => 'invalid headline alignment.',
                    'page.validation.headline_invalid_spacing' => 'invalid {field} spacing.',
                    'page.validation.highlight_invalid_variant' => 'invalid highlight variant.',
                    'page.validation.highlight_invalid_icon_mode' => 'invalid icon mode.',
                    'page.validation.cta_media_required' => 'media library PDF is required.',
                ];

                $message = $messages[$id] ?? $id;

                foreach ($parameters as $name => $value) {
                    $message = str_replace((string) $name, (string) $value, $message);
                }

                return $message;
            }

            #[\Override]
            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
