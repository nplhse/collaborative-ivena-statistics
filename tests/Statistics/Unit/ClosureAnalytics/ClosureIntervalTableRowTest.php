<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalTableRow;
use PHPUnit\Framework\TestCase;

final class ClosureIntervalTableRowTest extends TestCase
{
    public function testEventTypeHelpersMatchTheEventTableRow(): void
    {
        $group = $this->row(ClosureEventType::Group, 'group:1:demo');
        $cluster = $this->row(ClosureEventType::Cluster, 'cluster:1:abc');
        $single = $this->row(ClosureEventType::Single, 'interval:9');

        self::assertTrue($group->grouped());
        self::assertFalse($group->clustered());
        self::assertTrue($cluster->clustered());
        self::assertFalse($single->grouped());
        self::assertFalse($single->clustered());
    }

    private function row(ClosureEventType $type, string $eventKey): ClosureIntervalTableRow
    {
        return new ClosureIntervalTableRow(
            9,
            $eventKey,
            $type,
            'Demo Hospital',
            'Surgery',
            'Trauma',
            new \DateTimeImmutable('2026-07-15 10:00:00'),
            new \DateTimeImmutable('2026-07-15 12:00:00'),
            'emergency',
            'no_bed_capacity',
            'Ward',
            ClosureEventType::Group === $type ? 'demo' : null,
            120,
        );
    }
}
