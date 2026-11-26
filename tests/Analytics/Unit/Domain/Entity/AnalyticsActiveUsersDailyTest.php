<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\Domain\Entity;

use App\Analytics\Domain\Entity\AnalyticsActiveUsersDaily;
use PHPUnit\Framework\TestCase;

final class AnalyticsActiveUsersDailyTest extends TestCase
{
    public function testANewDayHasNoCountedWindows(): void
    {
        $row = new AnalyticsActiveUsersDaily(new \DateTimeImmutable('2026-11-18'));

        self::assertNull($row->getId());
        self::assertSame('2026-11-18', $row->getDate()->format('Y-m-d'));
        self::assertNull($row->getWau());
        self::assertNull($row->getMau());
    }

    public function testRecordKeepsTheStoredValueForAWindowThatWasNotCounted(): void
    {
        $row = new AnalyticsActiveUsersDaily(new \DateTimeImmutable('2026-11-18'));
        $row->record(5, 8);

        $row->record(6, null);

        self::assertSame(6, $row->getWau(), 'recounted');
        self::assertSame(8, $row->getMau(), 'kept');
    }
}
