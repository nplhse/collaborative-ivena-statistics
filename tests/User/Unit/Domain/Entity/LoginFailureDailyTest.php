<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\User\Domain\Entity\LoginFailureDaily;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginFailureDaily::class)]
final class LoginFailureDailyTest extends TestCase
{
    /** @return iterable<string, array{int, int, bool, bool}> */
    public static function patterns(): iterable
    {
        // max IPs per identifier, max identifiers per IP, many IPs, many identifiers
        yield 'below both thresholds' => [9, 9, false, false];
        yield 'one identifier from 10 IPs' => [10, 1, true, false];
        yield 'one IP against 10 identifiers' => [1, 10, false, true];
    }

    #[DataProvider('patterns')]
    public function testADayIsFlaggedFromTenIpsOrTenIdentifiers(int $maxIpsPerIdentifier, int $maxIdentifiersPerIp, bool $manyIps, bool $manyIdentifiers): void
    {
        $day = new LoginFailureDaily(new \DateTimeImmutable('2026-11-27'), 20, 0, 0, 0, 10, 10, $maxIpsPerIdentifier, $maxIdentifiersPerIp);

        $flagged = $day->isFlagged();

        self::assertSame($manyIps, $day->hasIdentifierFromManyIps());
        self::assertSame($manyIdentifiers, $day->hasIpAgainstManyIdentifiers());
        self::assertSame($manyIps || $manyIdentifiers, $flagged);
    }

    /** @return iterable<string, array{?\DateTimeImmutable, ?\DateTimeImmutable, list<string>}> */
    public static function daysToFold(): iterable
    {
        // last daily row, first failure, days written on 28 Nov
        yield 'first run without failures: yesterday' => [null, null, ['2026-11-27']];
        yield 'first run: from the day of the first failure' => [null, new \DateTimeImmutable('2026-11-25'), ['2026-11-25', '2026-11-26', '2026-11-27']];
        yield 'from the day after the last row' => [new \DateTimeImmutable('2026-11-25'), new \DateTimeImmutable('2026-11-20'), ['2026-11-26', '2026-11-27']];
        yield 'yesterday again on a second run' => [new \DateTimeImmutable('2026-11-27'), new \DateTimeImmutable('2026-11-20'), ['2026-11-27']];
        yield 'not older than single failures are kept' => [new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01'), self::range('2026-10-29', '2026-11-27')];
    }

    /** @param list<string> $expected */
    #[DataProvider('daysToFold')]
    public function testDaysToFold(?\DateTimeImmutable $lastAggregated, ?\DateTimeImmutable $firstEventDay, array $expected): void
    {
        $days = LoginFailureDaily::daysToFold($lastAggregated, $firstEventDay, new \DateTimeImmutable('2026-11-28'));

        self::assertSame($expected, array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $days));
    }

    /** @return list<string> */
    private static function range(string $from, string $to): array
    {
        $days = [];
        foreach (new \DatePeriod(new \DateTimeImmutable($from), new \DateInterval('P1D'), new \DateTimeImmutable($to.' +1 day')) as $day) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }
}
