<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentValidator;
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
                    'page.validation.accordion_items_required' => 'At least one accordion item is required.',
                    'page.validation.image_float_requires_non_full_width' => 'Text wrap requires a non-full-width image.',
                    'page.validation.highlight_icon_required' => 'A custom icon is required when icon mode is custom.',
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
