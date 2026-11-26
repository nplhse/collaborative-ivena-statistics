<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Form;

use App\Admin\UI\Form\PageContentDraftBuilder;
use App\Content\Application\Page\PageContentBlockDataNormalizer;
use PHPUnit\Framework\TestCase;

final class PageContentDraftBuilderTest extends TestCase
{
    public function testPostedBlocksBecomeOrderedDraftContentWithIncompleteInput(): void
    {
        $builder = new PageContentDraftBuilder(new PageContentBlockDataNormalizer());

        $draft = $builder->build([
            '0' => ['type' => 'quote', 'enabled' => '1', 'position' => '1', 'refusedTypeChange' => '', 'data' => ['text' => '']],
            '3' => ['type' => 'headline', 'position' => '0', 'data' => ['text' => 'Intro', 'level' => 'h2', 'html' => 'stale']],
        ]);

        self::assertSame([
            ['type' => 'headline', 'enabled' => false, 'data' => ['text' => 'Intro', 'level' => 'h2']],
            ['type' => 'quote', 'enabled' => true, 'data' => ['text' => '']],
        ], $draft);
    }

    public function testMalformedEntriesAreSkipped(): void
    {
        $builder = new PageContentDraftBuilder(new PageContentBlockDataNormalizer());

        self::assertSame(
            [['type' => 'richtext', 'enabled' => false, 'data' => []]],
            $builder->build(['x' => 'not a block', 'y' => ['data' => 'not data']]),
        );
    }
}
