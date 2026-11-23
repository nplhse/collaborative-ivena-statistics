<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Domain\Entity;

use App\Allocation\Domain\Entity\DistrictLegacyTakeover;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DistrictLegacyTakeoverTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testRecordsTheCompletionInTheSingleRow(): void
    {
        self::mockTime('2026-11-23 01:00:00');

        $marker = new DistrictLegacyTakeover('abc123');

        self::assertSame(DistrictLegacyTakeover::SINGLE_ROW_ID, $marker->getId());
        self::assertEquals(new \DateTimeImmutable('2026-11-23 01:00:00'), $marker->getCompletedAt());
        self::assertSame('abc123', $marker->getTransferFileHash());
    }
}
