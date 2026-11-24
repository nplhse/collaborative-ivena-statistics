<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentValidator;

/**
 * Card block, subpages block and the help blocks without settings (#1578).
 */
final class PageContentValidatorCardsTest extends PageContentValidatorTestCase
{
    public function testHelpBlocksAndSubpagesBlockNeedNoSettings(): void
    {
        $validator = new PageContentValidator($this->translator());

        self::assertSame([], $validator->validate([
            ['type' => 'help_search', 'data' => []],
            ['type' => 'help_topics', 'data' => []],
            ['type' => 'child_pages', 'data' => []],
            ['type' => 'child_pages', 'data' => ['numbered' => true]],
        ]));
        self::assertSame(['Block 1: page.validation.child_pages_numbered_must_be_bool'], $validator->validate([
            ['type' => 'child_pages', 'data' => ['numbered' => 'yes']],
        ]));
    }

    public function testValidCards(): void
    {
        $validator = new PageContentValidator($this->translator());

        self::assertSame([], $validator->validate([[
            'type' => 'cards',
            'data' => ['items' => [
                ['title' => 'Hospital staff', 'html' => '<p>Start here.</p>'],
                ['icon' => 'tabler:building-hospital', 'title' => 'Decision-makers', 'html' => '<p>Read this.</p>', 'linkUrl' => '/help/about', 'linkLabel' => 'About'],
                ['title' => 'Researchers', 'html' => '<p>Data.</p>', 'linkUrl' => 'https://example.org/paper'],
                ['title' => 'Anchor', 'html' => '<p>Jump.</p>', 'linkUrl' => '#contact'],
            ]],
        ]]));
    }

    public function testCardsNeedOneToFourItemsWithTitleAndText(): void
    {
        $validator = new PageContentValidator($this->translator());

        self::assertSame(['Block 1: page.validation.cards_items_required'], $validator->validate([
            ['type' => 'cards', 'data' => ['items' => []]],
        ]));

        $card = ['title' => 'Card', 'html' => '<p>Text</p>'];
        self::assertSame(['Block 1: page.validation.cards_too_many'], $validator->validate([
            ['type' => 'cards', 'data' => ['items' => [$card, $card, $card, $card, $card]]],
        ]));

        self::assertSame([
            'Block 1 card 1: data.html is required.',
            'Block 1 card 2: data.title is required.',
        ], $validator->validate([
            ['type' => 'cards', 'data' => ['items' => [['title' => 'Only a title'], ['html' => '<p>Only text</p>']]]],
        ]));
    }

    public function testCardIconsAndLinksAreChecked(): void
    {
        $validator = new PageContentValidator($this->translator());

        self::assertSame([
            'Block 1 card 1: page.validation.cards_invalid_icon',
            'Block 1 card 2: page.validation.cards_invalid_link',
            'Block 1 card 3: page.validation.cards_invalid_link',
            'Block 1 card 4: data.linkUrl is required.',
        ], $validator->validate([[
            'type' => 'cards',
            'data' => ['items' => [
                ['icon' => 'info circle', 'title' => 'A', 'html' => '<p>A</p>'],
                ['title' => 'B', 'html' => '<p>B</p>', 'linkUrl' => 'javascript:alert(1)'],
                ['title' => 'C', 'html' => '<p>C</p>', 'linkUrl' => '//evil.example'],
                ['title' => 'D', 'html' => '<p>D</p>', 'linkLabel' => 'Link without target'],
            ]],
        ]]));
    }

    public function testACardMustBeAnObject(): void
    {
        self::assertSame(['Block 1 card 1: must be an object.'], new PageContentValidator($this->translator())->validate([
            ['type' => 'cards', 'data' => ['items' => ['not a card']]],
        ]));
    }
}
