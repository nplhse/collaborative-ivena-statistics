<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Form;

use App\Admin\UI\Form\PageContentBlockTypeChange;
use App\Content\Domain\Enum\PageContentBlockType;
use PHPUnit\Framework\TestCase;

final class PageContentBlockTypeChangeTest extends TestCase
{
    public function testFieldsOfTheNewTypeArePassedThrough(): void
    {
        $change = PageContentBlockTypeChange::evaluate(
            PageContentBlockType::Richtext,
            PageContentBlockType::Quote,
            ['text' => 'A quote'],
            ['text'],
        );

        self::assertFalse($change->refused);
        self::assertSame(['text' => 'A quote'], $change->data);
    }

    public function testRichTextBodyStaysWhenChangingToHighlight(): void
    {
        $change = PageContentBlockType::Richtext->transferableFieldsTo(PageContentBlockType::Highlight);
        $evaluated = PageContentBlockTypeChange::evaluate(
            PageContentBlockType::Richtext,
            PageContentBlockType::Highlight,
            ['html' => '<p>Body</p>'],
            ['variant', 'title', 'html', 'iconMode', 'icon'],
        );

        self::assertSame(['html'], $change);
        self::assertFalse($evaluated->refused);
        self::assertSame(['html' => '<p>Body</p>'], $evaluated->data);
    }

    public function testChangeIsRefusedWhenContentOfTheOriginalTypeWouldBeLost(): void
    {
        $submitted = ['html' => '<p>Body</p>'];
        $change = PageContentBlockTypeChange::evaluate(
            PageContentBlockType::Richtext,
            PageContentBlockType::Quote,
            $submitted,
            ['text'],
        );

        self::assertTrue($change->refused);
        self::assertSame(['html'], $change->lostFields);
        self::assertSame($submitted, $change->data);
    }

    public function testEmptyMarkupAndPresentationSettingsAreDroppedWithoutRefusal(): void
    {
        $change = PageContentBlockTypeChange::evaluate(
            PageContentBlockType::Headline,
            PageContentBlockType::Quote,
            ['text' => 'Kept as quote text', 'level' => 'h3', 'align' => 'center'],
            ['text'],
        );
        $emptyRichText = PageContentBlockTypeChange::evaluate(
            PageContentBlockType::Richtext,
            PageContentBlockType::Quote,
            ['html' => '<div><br></div>', 'text' => 'New'],
            ['text'],
        );

        self::assertFalse($change->refused);
        self::assertSame(['text' => 'Kept as quote text'], $change->data);
        self::assertFalse($emptyRichText->refused);
        self::assertSame(['text' => 'New'], $emptyRichText->data);
    }

    public function testImageOnlyMarkupCountsAsContent(): void
    {
        $change = PageContentBlockTypeChange::evaluate(
            PageContentBlockType::Richtext,
            PageContentBlockType::Quote,
            ['html' => '<p><img src="/uploads/a.png" alt=""></p>'],
            ['text'],
        );

        self::assertTrue($change->refused);
    }

    public function testFieldsOfAThirdTypeAreDroppedWhenTheTypeIsUnchanged(): void
    {
        $change = PageContentBlockTypeChange::evaluate(
            PageContentBlockType::Quote,
            PageContentBlockType::Quote,
            ['text' => 'Quote', 'html' => '<p>Stale</p>'],
            ['text'],
        );

        self::assertFalse($change->refused);
        self::assertSame(['text' => 'Quote'], $change->data);
    }
}
