<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Audit;

use App\Admin\Application\Audit\AuditActivityGroup;
use App\Admin\Application\Audit\AuditActivityGrouper;
use App\Admin\Application\Audit\AuditRequestStack;
use App\Admin\Application\Audit\AuditRequestStats;
use App\Admin\Application\Audit\AuditTimelineEntry;
use PHPUnit\Framework\TestCase;

final class AuditActivityGrouperTest extends TestCase
{
    private const string S1 = '0193a4b2-6f1e-4c55-9d3e-000000000001';
    private const string S2 = '0193a4b2-6f1e-4c55-9d3e-000000000002';

    /**
     * Without session references (newest first): 11:00, 10:30 (30 min gap → same period), 09:59 (31 min → new).
     */
    public function testInferredPeriodsSplitWhenTheGapIsLongerThanThirtyMinutes(): void
    {
        $groups = new AuditActivityGrouper()->group([
            AuditTimelineFixture::entry(3, '11:00:00', 'r3'),
            AuditTimelineFixture::entry(2, '10:30:00', 'r2'),
            AuditTimelineFixture::entry(1, '09:59:00', 'r1'),
        ], false, false);

        self::assertSame([[AuditActivityGroup::INFERRED, [3, 2]], [AuditActivityGroup::INFERRED, [1]]], $this->describe($groups));
        self::assertSame('10:30:00', $groups[0]->start->format('H:i:s'));
        self::assertSame('11:00:00', $groups[0]->end->format('H:i:s'));
        self::assertTrue($groups[0]->isInferred());
    }

    public function testSessionsGroupBySessionReferenceRegardlessOfTime(): void
    {
        $groups = new AuditActivityGrouper()->group([
            AuditTimelineFixture::entry(4, '18:00:00', 'r4', sessionRef: self::S2),
            AuditTimelineFixture::entry(3, '12:00:00', 'r3', sessionRef: self::S1),
            AuditTimelineFixture::entry(2, '08:00:00', 'r2', sessionRef: self::S1),
            AuditTimelineFixture::entry(1, '07:59:00', 'r1'),
        ], false, false);

        self::assertSame([
            [AuditActivityGroup::SESSION, [4]],
            [AuditActivityGroup::SESSION, [3, 2]],
            [AuditActivityGroup::INFERRED, [1]],
        ], $this->describe($groups));
        self::assertSame(self::S1, $groups[1]->sessionRef);
        self::assertFalse($groups[1]->isInferred());
    }

    public function testAnInferredPeriodDoesNotSpanASession(): void
    {
        $groups = new AuditActivityGrouper()->group([
            AuditTimelineFixture::entry(3, '10:10:00', 'r3'),
            AuditTimelineFixture::entry(2, '10:05:00', 'r2', sessionRef: self::S1),
            AuditTimelineFixture::entry(1, '10:00:00', 'r1'),
        ], false, false);

        self::assertSame([
            [AuditActivityGroup::INFERRED, [3]],
            [AuditActivityGroup::SESSION, [2]],
            [AuditActivityGroup::INFERRED, [1]],
        ], $this->describe($groups));
    }

    public function testStackCountsWithItsTimeSpanAndEntries(): void
    {
        $stack = new AuditRequestStack('r2', [
            AuditTimelineFixture::entry(2, '10:00:00', 'r2'),
            AuditTimelineFixture::entry(3, '10:20:00', 'r2'),
        ], new AuditRequestStats(2, 2, 1, 1));

        $groups = new AuditActivityGrouper()->group([
            $stack,
            AuditTimelineFixture::entry(1, '09:30:00', 'r1'),
        ], false, false);

        self::assertCount(1, $groups, 'gap from the stack\'s oldest entry (10:00) to 09:30 is 30 minutes');
        self::assertSame(3, $groups[0]->entryCount());
        self::assertSame('10:20:00', $groups[0]->end->format('H:i:s'));
    }

    public function testGroupsAtThePageEdgesSayTheyMayContinue(): void
    {
        $groups = new AuditActivityGrouper()->group([
            AuditTimelineFixture::entry(2, '12:00:00', 'r2'),
            AuditTimelineFixture::entry(1, '09:00:00', 'r1'),
        ], true, true);

        self::assertSame([true, false], [$groups[0]->continuesNewer, $groups[0]->continuesOlder]);
        self::assertSame([false, true], [$groups[1]->continuesNewer, $groups[1]->continuesOlder]);
        self::assertSame([], new AuditActivityGrouper()->group([], true, true));
    }

    /**
     * @param list<AuditActivityGroup> $groups
     *
     * @return list<array{string, list<int>}>
     */
    private function describe(array $groups): array
    {
        return array_map(
            static fn (AuditActivityGroup $g): array => [
                $g->kind,
                array_map(static fn (AuditTimelineEntry|AuditRequestStack $i): int => $i instanceof AuditTimelineEntry ? $i->id : $i->first()->id, $g->items),
            ],
            $groups,
        );
    }
}
