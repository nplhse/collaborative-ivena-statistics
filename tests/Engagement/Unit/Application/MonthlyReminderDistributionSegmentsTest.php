<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Unit\Application;

use App\Engagement\Application\MonthlyReminderDistributionSegments;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MonthlyReminderDistributionSegmentsTest extends TestCase
{
    public function testGenderAndUrgencySegmentsReturnPercentages(): void
    {
        $segments = new MonthlyReminderDistributionSegments($this->translator());

        $genderSegments = $segments->genderSegments([
            'M' => 30,
            'F' => 70,
        ], 100, 'de');
        $urgencySegments = $segments->urgencySegments([
            1 => 10,
            2 => 20,
            3 => 70,
        ], 100, 'de');

        self::assertCount(3, $genderSegments);
        self::assertSame(30.0, $genderSegments[0]->percent);
        self::assertCount(3, $urgencySegments);
        self::assertSame(70.0, $urgencySegments[2]->percent);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
