<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\DTO;

use App\Admin\Application\DTO\Messenger\MessengerCountsDto;
use PHPUnit\Framework\TestCase;

final class MessengerCountsDtoTest extends TestCase
{
    public function testRatesUseFinalOutcomesAndAttempts(): void
    {
        $counts = new MessengerCountsDto(attempts: 8, handled: 6, handledAfterRetry: 1, retried: 1, failed: 1, durationMsSum: 800, durationMsMax: 300);

        self::assertSame(85.7, $counts->successRate(), '6 handled of 7 logical messages; the retry is not an outcome');
        self::assertSame(12.5, $counts->retryRate(), '1 retried of 8 attempts');
        self::assertSame(100, $counts->averageDurationMs());
    }

    public function testEmptyCountsHaveNoRates(): void
    {
        $counts = new MessengerCountsDto();

        self::assertNull($counts->successRate());
        self::assertNull($counts->retryRate());
        self::assertNull($counts->averageDurationMs());
    }

    public function testAddingSumsCountersAndKeepsTheMaximumAndLatestActivity(): void
    {
        $earlier = new \DateTimeImmutable('2026-10-26 08:00:00+00:00');
        $later = new \DateTimeImmutable('2026-10-26 09:00:00+00:00');

        $sum = new MessengerCountsDto(2, 2, 0, 0, 0, 100, 80, $later)
            ->add(new MessengerCountsDto(3, 1, 1, 1, 1, 300, 200, $earlier))
            ->add(new MessengerCountsDto());

        self::assertEquals(new MessengerCountsDto(5, 3, 1, 1, 1, 400, 200, $later), $sum);
    }
}
