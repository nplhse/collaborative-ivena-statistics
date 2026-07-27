<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentValidator;

final class PageContentValidatorStructureTest extends PageContentValidatorTestCase
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

    public function testAssertValidThrowsWhenInvalid(): void
    {
        $validator = new PageContentValidator($this->translator());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field "type" is required');

        $validator->assertValid([
            ['data' => []],
        ]);
    }
}
