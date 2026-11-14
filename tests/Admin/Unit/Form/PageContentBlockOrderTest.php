<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Form;

use App\Admin\UI\Form\PageContentBlockOrder;
use PHPUnit\Framework\TestCase;

final class PageContentBlockOrderTest extends TestCase
{
    public function testKeysAreOrderedByPosition(): void
    {
        self::assertSame([4, 0, 1], PageContentBlockOrder::sortKeys([0 => '1', 1 => '2', 4 => '0']));
    }

    public function testKeysWithoutValidPositionKeepTheirOrderAfterPositionedOnes(): void
    {
        self::assertSame(
            [2, 0, 1, 3],
            PageContentBlockOrder::sortKeys([0 => null, 1 => 'x', 2 => '0', 3 => '-1']),
        );
    }

    public function testBlocksAreSortedAndReturnedAsList(): void
    {
        $blocks = [0 => 'headline', 1 => 'richtext', 2 => 'accordion'];

        self::assertSame(
            ['accordion', 'headline', 'richtext'],
            PageContentBlockOrder::sortBlocks($blocks, [0 => '1', 1 => '2', 2 => '0']),
        );
    }

    public function testBlocksWithoutPositionsKeepTheirOrder(): void
    {
        self::assertSame(['a', 'b'], PageContentBlockOrder::sortBlocks([3 => 'a', 7 => 'b'], []));
    }
}
