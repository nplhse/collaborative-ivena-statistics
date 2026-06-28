<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Export;

use App\Allocation\Application\Export\ExportDateTimeRangeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportDateTimeRangeResolverTest extends TestCase
{
    private ExportDateTimeRangeResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->resolver = new ExportDateTimeRangeResolver();
    }

    public function testDefaultsMissingTimesToDayStartAndEnd(): void
    {
        $range = $this->resolver->resolve(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        self::assertSame('2026-01-01 00:00:00', $range->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-31 23:59:59', $range->to->format('Y-m-d H:i:s'));
    }

    public function testCombinesDateAndTimeIntoSingleInterval(): void
    {
        $range = $this->resolver->resolve(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            new \DateTimeImmutable('1970-01-01 08:00:00'),
            new \DateTimeImmutable('1970-01-01 18:00:00'),
        );

        self::assertSame('2026-01-01 08:00:00', $range->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-31 18:00:00', $range->to->format('Y-m-d H:i:s'));
    }

    public function testIntervalIsNotDailyWindowAcrossDays(): void
    {
        $range = $this->resolver->resolve(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-03'),
            new \DateTimeImmutable('1970-01-01 08:00:00'),
            new \DateTimeImmutable('1970-01-01 18:00:00'),
        );

        self::assertSame('2026-01-01 08:00:00', $range->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-03 18:00:00', $range->to->format('Y-m-d H:i:s'));
    }

    #[DataProvider('invalidRangeProvider')]
    public function testRejectsStartAfterEnd(\DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->resolve($from, $to);
    }

    /**
     * @return iterable<string, array{\DateTimeInterface, \DateTimeInterface}>
     */
    public static function invalidRangeProvider(): iterable
    {
        yield 'dates reversed' => [
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-01-01'),
        ];
    }
}
