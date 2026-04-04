<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\HourOfDayValueMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HourOfDayValueMapperTest extends TestCase
{
    public function testLabelPassesZeroPaddedHourToTranslator(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'statistics.distribution.hour.slot',
                ['hour' => '07'],
            )
            ->willReturn('07:00');

        $mapper = new HourOfDayValueMapper($translator);

        self::assertSame('07:00', $mapper->label(7));
    }

    public function testLabelUnknownForOutOfRange(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('statistics.distribution.hour.unknown')
            ->willReturn('Unknown');

        $mapper = new HourOfDayValueMapper($translator);

        self::assertSame('Unknown', $mapper->label(24));
    }
}
