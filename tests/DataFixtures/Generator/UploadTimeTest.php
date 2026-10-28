<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\DataFixtures\Generator\Application\UploadTime;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class UploadTimeTest extends TestCase
{
    public function testAnImportIsUploadedOneToThreeDaysAfterItsDataDuringOfficeHours(): void
    {
        $lastDay = new \DateTimeImmutable('2026-12-31 23:59:59');
        for ($seed = 0; $seed < 50; ++$seed) {
            $uploadedAt = UploadTime::after('2026-08-31', $lastDay, new Randomizer(new Mt19937($seed)));

            self::assertGreaterThanOrEqual('2026-09-01', $uploadedAt->format('Y-m-d'));
            self::assertLessThanOrEqual('2026-09-03', $uploadedAt->format('Y-m-d'));
            self::assertGreaterThanOrEqual(7, (int) $uploadedAt->format('G'));
            self::assertLessThanOrEqual(17, (int) $uploadedAt->format('G'));
        }
    }

    public function testTheUploadIsNeverAfterTheLastDayOfThePeriod(): void
    {
        $lastDay = new \DateTimeImmutable('2026-09-30 23:59:59');
        for ($seed = 0; $seed < 20; ++$seed) {
            self::assertSame('2026-09-30', UploadTime::after('2026-09-30', $lastDay, new Randomizer(new Mt19937($seed)))->format('Y-m-d'), 'the running month');
            self::assertLessThanOrEqual('2026-09-30', UploadTime::after('2026-09-29', $lastDay, new Randomizer(new Mt19937($seed)))->format('Y-m-d'));
        }
    }

    public function testTheSameStreamGivesTheSameTime(): void
    {
        $lastDay = new \DateTimeImmutable('2026-12-31 23:59:59');

        self::assertEquals(
            UploadTime::after('2026-08-31', $lastDay, new Randomizer(new Mt19937(740))),
            UploadTime::after('2026-08-31', $lastDay, new Randomizer(new Mt19937(740))),
        );
    }
}
