<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Enum;

use App\Content\Domain\Enum\PageContentBlockType;
use PHPUnit\Framework\TestCase;

final class PageContentBlockTypeTest extends TestCase
{
    public function testTranslationKeyUsesBlockValue(): void
    {
        self::assertSame('label.block_type.headline', PageContentBlockType::Headline->translationKey());
    }

    public function testValuesReturnsAllCaseValues(): void
    {
        self::assertSame(
            ['richtext', 'image', 'cta', 'quote', 'headline', 'highlight', 'accordion'],
            PageContentBlockType::values(),
        );
    }

    public function testFormChoicesMapsTranslationKeysToValues(): void
    {
        $choices = PageContentBlockType::formChoices();

        self::assertSame('headline', $choices['label.block_type.headline']);
        self::assertSame('accordion', $choices['label.block_type.accordion']);
        self::assertCount(count(PageContentBlockType::cases()), $choices);
    }

    public function testTryFromStringReturnsNullForEmptyValues(): void
    {
        self::assertNull(PageContentBlockType::tryFromString(null));
        self::assertNull(PageContentBlockType::tryFromString(''));
        self::assertNull(PageContentBlockType::tryFromString('   '));
    }

    public function testTryFromStringReturnsMatchingCase(): void
    {
        self::assertSame(PageContentBlockType::Highlight, PageContentBlockType::tryFromString('highlight'));
    }
}
