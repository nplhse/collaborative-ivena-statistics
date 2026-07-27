<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentValidator;

final class PageContentValidatorBlockFieldsTest extends PageContentValidatorTestCase
{
    public function testAccordionRequiresAtLeastOneItem(): void
    {
        $validator = new PageContentValidator($this->translator());

        $errors = $validator->validate([
            ['type' => 'accordion', 'data' => ['items' => []]],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('accordion item', implode(' ', $errors));
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
}
