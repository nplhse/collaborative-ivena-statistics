<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\LoginFailure;

use App\User\Application\LoginFailure\LoginFailureTrend;
use App\User\Domain\Entity\LoginFailureDaily;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginFailureTrend::class)]
final class LoginFailureTrendTest extends TestCase
{
    public function testShowsTheThirtyFinishedDaysOldestFirst(): void
    {
        $trend = LoginFailureTrend::build([], 0, new \DateTimeImmutable('2026-11-28'));

        self::assertCount(30, $trend->days);
        self::assertSame('2026-10-29', $trend->days[0]['date']->format('Y-m-d'));
        self::assertSame('2026-11-27', $trend->days[29]['date']->format('Y-m-d'));
    }

    public function testADayWithoutARowIsNotCounted(): void
    {
        $trend = LoginFailureTrend::build([$this->day('2026-11-27', failures: 5, refused: 2)], 0, new \DateTimeImmutable('2026-11-28'));

        self::assertNull($trend->days[28]['attempts']);
        self::assertSame(7, $trend->days[29]['attempts']);
    }

    public function testSumsAttemptsAndFlaggedDays(): void
    {
        // 26 Nov: 3 + 1 attempts, flagged; 27 Nov: 5 attempts. 28 Oct is older than 30 days.
        $daily = [
            $this->day('2026-11-27', failures: 5),
            $this->day('2026-11-26', failures: 3, refused: 1, maxIpsPerIdentifier: 10),
            $this->day('2026-10-28', failures: 100, maxIpsPerIdentifier: 10),
        ];

        $trend = LoginFailureTrend::build($daily, 2, new \DateTimeImmutable('2026-11-28'));

        self::assertSame([9, 1, 2, 5], [$trend->attempts, $trend->flaggedDays, $trend->lockedAccounts, $trend->peak()]);
    }

    public function testThePeakIsAtLeastOne(): void
    {
        $trend = LoginFailureTrend::build([], 0, new \DateTimeImmutable('2026-11-28'));

        self::assertSame(1, $trend->peak());
    }

    private function day(string $date, int $failures, int $refused = 0, int $maxIpsPerIdentifier = 1): LoginFailureDaily
    {
        return new LoginFailureDaily(new \DateTimeImmutable($date), $failures, $refused, 0, 0, 1, 1, $maxIpsPerIdentifier, 1);
    }
}
