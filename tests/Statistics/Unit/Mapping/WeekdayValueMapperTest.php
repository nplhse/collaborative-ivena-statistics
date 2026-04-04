<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\WeekdayValueMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WeekdayValueMapperTest extends TestCase
{
    public function testLabelUsesIsoWeekdayKey(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('statistics.distribution.weekday.n3')
            ->willReturn('Wednesday');

        self::assertSame('Wednesday', new WeekdayValueMapper($translator)->label(3));
    }

    public function testLabelUnknownWhenInvalid(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('statistics.distribution.weekday.unknown')
            ->willReturn('Unknown');

        self::assertSame('Unknown', new WeekdayValueMapper($translator)->label(0));
    }
}
