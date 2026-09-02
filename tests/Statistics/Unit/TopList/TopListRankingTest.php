<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\TopList;

use App\Statistics\Application\TopList\TopListLimit;
use App\Statistics\Application\TopList\TopListRanking;
use PHPUnit\Framework\TestCase;

final class TopListRankingTest extends TestCase
{
    public function testFromAggregatesTruncatesAllSafetyCap(): void
    {
        $rows = [];
        for ($i = 1; $i <= TopListLimit::ALL_SAFETY_CAP + 5; ++$i) {
            $rows[] = ['label' => 'Item '.$i, 'count' => 100 - ($i % 50), 'entityId' => $i];
        }

        $ranking = TopListRanking::fromAggregates($rows, 1000, TopListLimit::ALL_SAFETY_CAP + 1);

        self::assertTrue($ranking->truncated);
        self::assertSame(TopListLimit::ALL_SAFETY_CAP, $ranking->count());
        self::assertSame(1, $ranking->rows[0]->rank);
        self::assertSame(TopListLimit::ALL_SAFETY_CAP, $ranking->rows[TopListLimit::ALL_SAFETY_CAP - 1]->rank);
    }

    public function testPageSliceKeepsOriginalRanks(): void
    {
        $ranking = TopListRanking::fromAggregates([
            ['label' => 'A', 'count' => 10, 'entityId' => 1],
            ['label' => 'B', 'count' => 9, 'entityId' => 2],
            ['label' => 'C', 'count' => 8, 'entityId' => 3],
        ], 27, 25);

        $page = $ranking->pageSlice(2, 2);

        self::assertCount(1, $page->rows);
        self::assertSame('C', $page->rows[0]->label);
        self::assertSame(3, $page->rows[0]->rank);
        self::assertFalse($page->truncated);
    }

    public function testFromAggregatesLocalizesUnknownAndEmptyLabels(): void
    {
        $ranking = TopListRanking::fromAggregates([
            ['label' => 'Unknown', 'count' => 10],
            ['label' => '', 'count' => 5, 'entityId' => 1],
            ['label' => 'Kardiologie', 'count' => 3, 'entityId' => 2],
        ], 18, 25, 'Unbekannt');

        self::assertSame('Unbekannt', $ranking->rows[0]->label);
        self::assertSame('Unbekannt', $ranking->rows[1]->label);
        self::assertSame('Kardiologie', $ranking->rows[2]->label);
    }
}
