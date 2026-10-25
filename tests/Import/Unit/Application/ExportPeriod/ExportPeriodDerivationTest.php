<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\ExportPeriod;

use App\Import\Application\ExportPeriod\ExportPeriod;
use App\Import\Application\ExportPeriod\ExportPeriodDerivation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportPeriodDerivation::class)]
#[CoversClass(ExportPeriod::class)]
final class ExportPeriodDerivationTest extends TestCase
{
    #[Test]
    public function allocationPeriodCoversWholeDaysOfFirstAndLastAllocation(): void
    {
        $period = ExportPeriodDerivation::forAllocations(new \DateTimeImmutable('2026-03-01 00:07:00'), new \DateTimeImmutable('2026-03-31 23:58:00'));

        self::assertNotNull($period);
        self::assertSame('2026-03-01 00:00', $period->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-04-01 00:00', $period->endsAt->format('Y-m-d H:i'));
    }

    #[Test]
    public function allocationPeriodIsNullWithoutRows(): void
    {
        self::assertNull(ExportPeriodDerivation::forAllocations(null, null));
    }

    #[Test]
    public function closurePeriodUsesWholeDaysWithoutMatchingAllocationMonths(): void
    {
        $period = ExportPeriodDerivation::forClosures(
            new \DateTimeImmutable('2026-03-03 10:00:00'),
            new \DateTimeImmutable('2026-03-27 14:30:00'),
            [],
        );

        self::assertNotNull($period);
        self::assertSame('2026-03-03 00:00', $period->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-03-28 00:00', $period->endsAt->format('Y-m-d H:i'));
    }

    #[Test]
    public function closureEndAtMidnightIsNotExtendedByAnotherDay(): void
    {
        $period = ExportPeriodDerivation::forClosures(
            new \DateTimeImmutable('2026-03-03 10:00:00'),
            new \DateTimeImmutable('2026-03-20 00:00:00'),
            [],
        );

        self::assertNotNull($period);
        self::assertSame('2026-03-20 00:00', $period->endsAt->format('Y-m-d H:i'));
    }

    #[Test]
    public function closurePeriodWidensToMonthsCoveredByAllocationImports(): void
    {
        $period = ExportPeriodDerivation::forClosures(
            new \DateTimeImmutable('2026-03-03 10:00:00'),
            new \DateTimeImmutable('2026-04-01 00:00:00'),
            [
                new ExportPeriod(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-03-01')),
                new ExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')),
            ],
        );

        self::assertNotNull($period);
        self::assertSame('2026-03-01 00:00', $period->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-04-01 00:00', $period->endsAt->format('Y-m-d H:i'));
    }

    #[Test]
    public function closurePeriodStaysOnDaysWhenAllocationsCoverOnlyPartOfTheMonths(): void
    {
        $period = ExportPeriodDerivation::forClosures(
            new \DateTimeImmutable('2026-03-03 10:00:00'),
            new \DateTimeImmutable('2026-04-10 08:00:00'),
            [new ExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01'))],
        );

        self::assertNotNull($period);
        self::assertSame('2026-03-03 00:00', $period->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-04-11 00:00', $period->endsAt->format('Y-m-d H:i'));
    }

    #[Test]
    public function mergeJoinsTouchingAndOverlappingPeriods(): void
    {
        $merged = ExportPeriod::merge([
            new ExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-01')),
            new ExportPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-01')),
            new ExportPeriod(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-03-05')),
            new ExportPeriod(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-06-01')),
        ]);

        self::assertCount(2, $merged);
        self::assertSame('2026-01-01', $merged[0]->startsAt->format('Y-m-d'));
        self::assertSame('2026-04-01', $merged[0]->endsAt->format('Y-m-d'));
        self::assertSame('2026-05-01', $merged[1]->startsAt->format('Y-m-d'));
    }

    #[Test]
    public function rejectsEmptyPeriods(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExportPeriod(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-01'));
    }
}
