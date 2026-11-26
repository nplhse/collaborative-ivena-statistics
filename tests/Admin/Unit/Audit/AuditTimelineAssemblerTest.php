<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Audit;

use App\Admin\Application\Audit\AuditRequestStack;
use App\Admin\Application\Audit\AuditRequestStats;
use App\Admin\Application\Audit\AuditTimelineAssembler;
use App\Admin\Application\Audit\AuditTimelineEntry;
use PHPUnit\Framework\TestCase;

final class AuditTimelineAssemblerTest extends TestCase
{
    /**
     * Page (newest first): 6 r2, 5 r1, 4 r3, 3 r1, 2 r1. r1 has 3 entries, r2 and r3 one each.
     * Expected: 6 single, stack r1 at the position of entry 5 with 2 → 3 → 5, then 4 single.
     */
    public function testStacksByRequestIdAtTheNewestMemberAndOrdersMembersOldestFirst(): void
    {
        $entries = [
            AuditTimelineFixture::entry(6, '10:00:05', 'r2'),
            AuditTimelineFixture::entry(5, '10:00:04', 'r1'),
            AuditTimelineFixture::entry(4, '10:00:03', 'r3'),
            AuditTimelineFixture::entry(3, '10:00:02', 'r1'),
            AuditTimelineFixture::entry(2, '10:00:02', 'r1'),
        ];

        $items = new AuditTimelineAssembler()->assemble($entries, [
            'r1' => new AuditRequestStats(3, 3, 1, 1),
            'r2' => new AuditRequestStats(1, 1, 1, 1),
            'r3' => new AuditRequestStats(1, 1, 1, 1),
        ]);

        self::assertSame(['entry:6', 'stack:r1', 'entry:4'], $this->describe($items));
        self::assertInstanceOf(AuditRequestStack::class, $items[1]);
        self::assertSame([2, 3, 5], $this->ids($items[1]->entries), 'same second: id decides');
        self::assertTrue($items[1]->isComplete());
    }

    public function testRequestWithMoreEntriesThanThePageIsAnIncompleteStack(): void
    {
        $items = new AuditTimelineAssembler()->assemble(
            [AuditTimelineFixture::entry(9, '10:00:00', 'big')],
            ['big' => new AuditRequestStats(1200, 1200, 1, 1)],
        );

        self::assertSame(['stack:big'], $this->describe($items));
        self::assertInstanceOf(AuditRequestStack::class, $items[0]);
        self::assertFalse($items[0]->isComplete());
    }

    public function testEntryWithoutStatsStaysSingle(): void
    {
        $items = new AuditTimelineAssembler()->assemble([AuditTimelineFixture::entry(1, '10:00:00', 'r1')], []);

        self::assertSame(['entry:1'], $this->describe($items));
    }

    public function testLegacyRequestIsStackedOnlyWithOneActorAndOneOrigin(): void
    {
        $entries = [
            AuditTimelineFixture::entry(4, '10:00:03', 'mixed', actorId: 2, legacy: true),
            AuditTimelineFixture::entry(3, '10:00:02', 'mixed', actorId: 1, legacy: true),
            AuditTimelineFixture::entry(2, '10:00:01', 'clean', legacy: true),
            AuditTimelineFixture::entry(1, '10:00:00', 'clean', legacy: true),
        ];

        $items = new AuditTimelineAssembler()->assemble($entries, [
            'mixed' => new AuditRequestStats(2, 2, 2, 1),
            'clean' => new AuditRequestStats(2, 2, 1, 1),
        ]);

        self::assertSame(['entry:4', 'entry:3', 'stack:clean'], $this->describe($items));
        self::assertInstanceOf(AuditRequestStack::class, $items[2]);
        self::assertTrue($items[2]->isLegacy());
    }

    public function testGeneratedRequestIsStackedAcrossActorsAndOrigins(): void
    {
        $entries = [
            AuditTimelineFixture::entry(2, '10:05:00', 'r1', actorId: 1, origin: 'messenger'),
            AuditTimelineFixture::entry(1, '10:00:00', 'r1', actorId: 1, origin: 'http'),
        ];

        $items = new AuditTimelineAssembler()->assemble($entries, ['r1' => new AuditRequestStats(2, 2, 1, 2)]);

        self::assertSame(['stack:r1'], $this->describe($items));
        self::assertInstanceOf(AuditRequestStack::class, $items[0]);
        self::assertSame([1], $this->ids($items[0]->ownEntries()));
        self::assertSame([2], $this->ids($items[0]->backgroundEntries()));
    }

    /**
     * @param list<AuditTimelineEntry|AuditRequestStack> $items
     *
     * @return list<string>
     */
    private function describe(array $items): array
    {
        return array_map(
            static fn (AuditTimelineEntry|AuditRequestStack $item): string => $item instanceof AuditRequestStack
                ? 'stack:'.$item->requestId
                : 'entry:'.$item->id,
            $items,
        );
    }

    /**
     * @param list<AuditTimelineEntry> $entries
     *
     * @return list<int>
     */
    private function ids(array $entries): array
    {
        return array_map(static fn (AuditTimelineEntry $e): int => $e->id, $entries);
    }
}
