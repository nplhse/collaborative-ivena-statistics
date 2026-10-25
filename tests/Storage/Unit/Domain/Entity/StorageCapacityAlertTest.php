<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Domain\Entity;

use App\Storage\Domain\Entity\StorageCapacityAlert;
use App\Storage\Domain\Enum\CapacityLevel;
use PHPUnit\Framework\TestCase;

final class StorageCapacityAlertTest extends TestCase
{
    public function testRecordsTheNotifiedLevel(): void
    {
        $alert = new StorageCapacityAlert(CapacityLevel::Notice, new \DateTimeImmutable('2026-10-24 03:30'), new \DateTimeImmutable('2026-10-24 03:31'));

        $alert->record(CapacityLevel::Critical, new \DateTimeImmutable('2026-10-25 03:30'), new \DateTimeImmutable('2026-10-25 03:31'));

        self::assertSame(StorageCapacityAlert::SINGLETON_ID, $alert->getId());
        self::assertSame(CapacityLevel::Critical, $alert->getLastNotifiedLevel());
        self::assertSame('2026-10-25 00:00', $alert->getChangedOn()->format('Y-m-d H:i'));
        self::assertSame('2026-10-25 03:31', $alert->getUpdatedAt()->format('Y-m-d H:i'));
    }

    public function testNeverRecordsAnUnknownLevel(): void
    {
        $alert = new StorageCapacityAlert(CapacityLevel::Warning, new \DateTimeImmutable(), new \DateTimeImmutable());

        $this->expectException(\InvalidArgumentException::class);

        $alert->record(CapacityLevel::Unknown, new \DateTimeImmutable(), new \DateTimeImmutable());
    }
}
