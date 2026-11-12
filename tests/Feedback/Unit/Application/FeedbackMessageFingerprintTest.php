<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Application;

use App\Feedback\Application\FeedbackMessageFingerprint;
use PHPUnit\Framework\TestCase;

final class FeedbackMessageFingerprintTest extends TestCase
{
    public function testFingerprintIgnoresCaseLinksDigitsAndSpacing(): void
    {
        $expected = hash('sha256', 'read the news and earn $ , per month');

        self::assertSame($expected, FeedbackMessageFingerprint::fromMessage('Read the news and earn $50,000 per month https://telegra.ph/a-1'));
        self::assertSame($expected, FeedbackMessageFingerprint::fromMessage("READ  the news and earn \$70,000\nper month www.example.test/b"));
    }

    public function testDifferentWordsGiveDifferentFingerprints(): void
    {
        self::assertNotSame(
            FeedbackMessageFingerprint::fromMessage('Hola, quería saber tu precio.'),
            FeedbackMessageFingerprint::fromMessage('Hej, jeg ønskede at kende din pris.'),
        );
    }

    public function testNothingLeftGivesNoFingerprint(): void
    {
        self::assertNull(FeedbackMessageFingerprint::fromMessage('https://example.test 123 456'));
    }
}
