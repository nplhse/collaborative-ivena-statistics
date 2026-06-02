<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentBlockDataNormalizer;
use PHPUnit\Framework\TestCase;

final class PageContentBlockDataNormalizerTest extends TestCase
{
    public function testStripsFieldsNotMatchingBlockType(): void
    {
        $sut = new PageContentBlockDataNormalizer();

        $normalized = $sut->normalize([
            [
                'type' => 'image',
                'enabled' => true,
                'data' => [
                    'html' => '<p>legacy</p>',
                    'alt' => 'Alt text',
                    'src' => '/uploads/media/x.png',
                ],
            ],
        ]);

        self::assertSame([
            [
                'type' => 'image',
                'enabled' => true,
                'data' => [
                    'alt' => 'Alt text',
                    'src' => '/uploads/media/x.png',
                ],
            ],
        ], $normalized);
    }
}
