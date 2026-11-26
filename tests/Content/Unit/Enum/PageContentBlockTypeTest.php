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
            ['richtext', 'image', 'cta', 'quote', 'headline', 'highlight', 'accordion', 'help_search', 'help_topics', 'cards', 'child_pages', 'read_more', 'board_members'],
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

    public function testOnlyRichTextBodiesAreTransferableBetweenTypes(): void
    {
        self::assertSame(['html'], PageContentBlockType::Richtext->transferableFieldsTo(PageContentBlockType::Highlight));
        self::assertSame(['html'], PageContentBlockType::Highlight->transferableFieldsTo(PageContentBlockType::Richtext));
        self::assertSame([], PageContentBlockType::Quote->transferableFieldsTo(PageContentBlockType::Headline));
        self::assertSame([], PageContentBlockType::Image->transferableFieldsTo(PageContentBlockType::Cta));
    }

    public function testEveryTypeDeclaresContentFields(): void
    {
        // The help blocks (#596) have no fields: their content comes from the help tree when the page is shown.
        $withoutFields = [PageContentBlockType::HelpSearch, PageContentBlockType::HelpTopics, PageContentBlockType::ChildPages, PageContentBlockType::BoardMembers];

        foreach (PageContentBlockType::cases() as $type) {
            if (\in_array($type, $withoutFields, true)) {
                self::assertSame([], $type->contentFields(), $type->value);

                continue;
            }

            self::assertNotSame([], $type->contentFields(), $type->value);
            self::assertSame($type->contentFields(), $type->transferableFieldsTo($type));
        }
    }
}
