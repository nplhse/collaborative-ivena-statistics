<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Unit\Domain\Entity;

use App\Kpi\Domain\Entity\KpiDaily;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class KpiDailyTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testTimestampsComeFromTheClock(): void
    {
        self::mockTime('2026-05-15 10:00:00');
        $kpi = new KpiDaily(new \DateTimeImmutable('2026-05-14'), null, 3, 2, 100, 100, 5, 1);

        self::assertSame('2026-05-15 10:00:00', $kpi->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($kpi->getUpdatedAt());

        self::mockTime('2026-05-16 08:30:00');
        $kpi->onPreUpdate();

        self::assertSame('2026-05-16 08:30:00', $kpi->getUpdatedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-15 10:00:00', $kpi->getCreatedAt()->format('Y-m-d H:i:s'));
    }
}
