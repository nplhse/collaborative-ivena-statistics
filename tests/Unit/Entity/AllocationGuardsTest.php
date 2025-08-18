<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Allocation;
use PHPUnit\Framework\TestCase;

final class AllocationGuardsTest extends TestCase
{
    public function testSetAgeRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/age/i');

        new Allocation()->setAge(-1);
    }

    public function testSetAgeRejectsTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/age/i');

        new Allocation()->setAge(123);
    }

    public function testSetAgeAcceptsBoundaryValues(): void
    {
        $a = new Allocation();

        $a->setAge(1);
        self::assertSame(1, $a->getAge());

        $a->setAge(42);
        self::assertSame(42, $a->getAge());

        $a->setAge(99);
        self::assertSame(99, $a->getAge());
    }

    public function testArrivalBeforeCreatedAtThrows(): void
    {
        $alloc = new Allocation();
        $alloc->setCreatedAt(new \DateTimeImmutable('2025-01-01 10:00:00'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/arrival.*before.*created/i');

        $alloc->setArrivalAt(new \DateTimeImmutable('2025-01-01 09:59:59'));
    }

    public function testArrivalEqualOrAfterCreatedAtIsAllowed(): void
    {
        $alloc = new Allocation();
        $alloc->setCreatedAt(new \DateTimeImmutable('2025-01-01 10:00:00'));

        // Exactly at the same time
        $alloc->setArrivalAt(new \DateTimeImmutable('2025-01-01 10:00:00'));
        self::assertSame('2025-01-01 10:00:00', $alloc->getArrivalAt()->format('Y-m-d H:i:s'));

        // Afterwards
        $alloc->setArrivalAt(new \DateTimeImmutable('2025-01-01 10:00:01'));
        self::assertSame('2025-01-01 10:00:01', $alloc->getArrivalAt()->format('Y-m-d H:i:s'));
    }
}
