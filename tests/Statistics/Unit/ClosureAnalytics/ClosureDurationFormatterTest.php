<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\ClosureDurationFormatter;
use PHPUnit\Framework\TestCase;

final class ClosureDurationFormatterTest extends TestCase
{
    public function testFormatsMinutesAsReadableDuration(): void
    {
        self::assertSame('0 min', ClosureDurationFormatter::humanize(0));
        self::assertSame('1 h 5 min', ClosureDurationFormatter::humanize(65));
        self::assertSame('2 d 3 h', ClosureDurationFormatter::humanize(3_060));
        self::assertSame('2 w 1 d', ClosureDurationFormatter::humanize(21_600));
        self::assertSame('2 w 1 d 3 h', ClosureDurationFormatter::humanize(21_780));
        self::assertSame('1 d', ClosureDurationFormatter::humanize(1_470));
    }
}
