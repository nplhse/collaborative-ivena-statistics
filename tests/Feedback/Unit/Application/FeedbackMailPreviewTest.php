<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Application;

use App\Feedback\Application\FeedbackMailPreview;
use PHPUnit\Framework\TestCase;

final class FeedbackMailPreviewTest extends TestCase
{
    public function testStripsHttpAndWwwUrls(): void
    {
        $preview = FeedbackMailPreview::fromMessage(
            'Hello https://spam.example/path and www.evil.test/x thanks',
        );

        self::assertSame('Hello and thanks', $preview);
        self::assertStringNotContainsString('https://', $preview);
        self::assertStringNotContainsString('www.', $preview);
    }

    public function testTruncatesToMaxLengthWithEllipsis(): void
    {
        $message = str_repeat('a', FeedbackMailPreview::MAX_LENGTH + 50);
        $preview = FeedbackMailPreview::fromMessage($message);

        self::assertSame(FeedbackMailPreview::MAX_LENGTH + 1, mb_strlen($preview));
        self::assertStringEndsWith('…', $preview);
        self::assertSame(str_repeat('a', FeedbackMailPreview::MAX_LENGTH).'…', $preview);
    }

    public function testStripsUrlsBeforeTruncating(): void
    {
        $url = 'https://spam.example/'.str_repeat('x', 300);
        $preview = FeedbackMailPreview::fromMessage('Start '.$url.' end text that remains');

        self::assertSame('Start end text that remains', $preview);
    }

    public function testNormalizesWhitespace(): void
    {
        $preview = FeedbackMailPreview::fromMessage("Line one\n\n  line   two  ");

        self::assertSame('Line one line two', $preview);
    }
}
