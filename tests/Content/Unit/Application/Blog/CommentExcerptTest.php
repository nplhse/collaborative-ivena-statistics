<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Application\Blog;

use App\Content\Application\Blog\CommentExcerpt;
use PHPUnit\Framework\TestCase;

final class CommentExcerptTest extends TestCase
{
    public function testShortContentIsReturnedUnchanged(): void
    {
        self::assertSame('Kurzer Text', CommentExcerpt::from('<p>Kurzer Text</p>'));
    }

    public function testLongContentIsTruncatedToOneHundredTwentyCharacters(): void
    {
        $excerpt = CommentExcerpt::from(str_repeat('Wort ', 80));

        self::assertLessThanOrEqual(CommentExcerpt::LENGTH, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
    }
}
