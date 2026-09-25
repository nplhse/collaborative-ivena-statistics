<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\UI\Twig;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\Insights\InsightValueRow;
use App\Statistics\UI\Twig\RankingTable\RankingTableRankShift;
use App\Statistics\UI\Twig\RankingTable\RankingTableRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RankingTableRowsTest extends TestCase
{
    public function testFromTableWidgetMapsStringCellsAndParallelArrays(): void
    {
        $labelTarget = new StatisticWidgetNavigationTarget('stats.insights.open', 'app_explore_indication_show', ['publicId' => 'acs']);
        $insightTarget = new StatisticWidgetNavigationTarget('stats.insights.open', 'app_stats_insights_show', ['dimension' => 'indications', 'id' => 7]);

        $rows = RankingTableRows::fromTableWidget([
            'headerTranslationKeys' => [
                'stats.top_lists.table.rank',
                'stats.top_lists.table.diagnosis',
                'stats.top_lists.table.count',
                'stats.top_lists.table.share',
            ],
            'rows' => [
                ['1', 'ACS', '120', '12.5%'],
            ],
            'numericColumnStartIndex' => 3,
            'labelRowTargets' => [$labelTarget],
            'insightRowTargets' => [$insightTarget],
            'shareBars' => [12.5],
        ], static fn (StatisticWidgetNavigationTarget $target): string => $target->route.'/'.$target->labelTranslationKey);

        self::assertCount(1, $rows);
        self::assertSame('1', $rows[0]->rank);
        self::assertSame('ACS', $rows[0]->label);
        self::assertSame('120', $rows[0]->count);
        self::assertSame('12.5%', $rows[0]->share);
        self::assertSame('app_explore_indication_show/stats.insights.open', $rows[0]->labelHref);
        self::assertSame(12.5, $rows[0]->shareBar);
        self::assertNotNull($rows[0]->action);
        self::assertSame('app_stats_insights_show/stats.insights.open', $rows[0]->action->href);
        self::assertSame('tabler:chart-bar', $rows[0]->action->icon);
        self::assertSame('stats-top-lists-row-insight', $rows[0]->action->testId);
        self::assertNull($rows[0]->countDelta);
        self::assertNull($rows[0]->rankShift);
    }

    public function testFromTableWidgetRejectsAnalysisPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RankingTableRows::fromTableWidget([
            'rows' => [['Jan', '10']],
            'monthRowTargets' => [null],
            'summaryStats' => ['meanDisplay' => '1', 'stdDevDisplay' => '0'],
            'footerRow' => ['labelTranslationKey' => 'stats.overview.table.total'],
        ], static fn (): string => '/unused');
    }

    public function testFromInsightValuesFormatsRankCountShareAndExploreAction(): void
    {
        $rows = new RankingTableRows()->fromInsightValues([
            new InsightValueRow(
                id: 4,
                label: 'STEMI',
                count: 1200,
                url: '/statistics/insights/indications/4',
                shareDisplay: '12.5%',
                rank: 2,
                contextLabel: 'Cardiology',
                exploreUrl: '/explore/indications/stemi',
            ),
            new InsightValueRow(
                id: 5,
                label: 'Other',
                count: 3,
                url: '/statistics/insights/indications/5',
                shareDisplay: '0.0%',
                rank: 3,
            ),
        ], 'stats-insights-row-explore');

        self::assertSame('2', $rows[0]->rank);
        self::assertSame('1.200', $rows[0]->count);
        self::assertSame('12.5%', $rows[0]->share);
        self::assertSame('Cardiology', $rows[0]->labelContext);
        self::assertSame('/statistics/insights/indications/4', $rows[0]->labelHref);
        self::assertNotNull($rows[0]->action);
        self::assertSame('/explore/indications/stemi', $rows[0]->action->href);
        self::assertSame('tabler:book-2', $rows[0]->action->icon);
        self::assertSame('stats.indication.nav.catalog', $rows[0]->action->labelKey);
        self::assertSame('stats-insights-row-explore', $rows[0]->action->testId);
        self::assertNull($rows[0]->shareBar);
        self::assertNull($rows[1]->action);
    }

    public function testFromInsightValuesDropsMissingRankShareAndExploreUrl(): void
    {
        $rows = new RankingTableRows()->fromInsightValues([
            new InsightValueRow(
                id: 1,
                label: 'Plain',
                count: 0,
                url: '/statistics/insights/indications/1',
                shareDisplay: null,
                rank: null,
                exploreUrl: '',
            ),
        ], 'stats-insights-row-explore');

        self::assertSame('', $rows[0]->rank);
        self::assertSame('0', $rows[0]->count);
        self::assertSame('', $rows[0]->share);
        self::assertNull($rows[0]->labelContext);
        self::assertNull($rows[0]->action);
    }

    public function testFromTableWidgetIgnoresNonTargetsAndNonNumericShares(): void
    {
        $rows = RankingTableRows::fromTableWidget([
            'rows' => [
                [1, 'ACS', 12, 3.5],
                [null, ['nested'], new \stdClass(), true],
            ],
            'labelRowTargets' => 'not-a-list',
            'insightRowTargets' => [null, 'not-a-target'],
            'shareBars' => ['n/a', 4],
        ], static fn (): string => '/unused');

        self::assertSame('1', $rows[0]->rank);
        self::assertSame('ACS', $rows[0]->label);
        self::assertSame('12', $rows[0]->count);
        self::assertSame('3.5', $rows[0]->share);
        self::assertNull($rows[0]->labelHref);
        self::assertNull($rows[0]->shareBar);
        self::assertNull($rows[0]->action);
        self::assertSame('', $rows[1]->rank);
        self::assertSame('', $rows[1]->label);
        self::assertSame('', $rows[1]->count);
        self::assertSame('1', $rows[1]->share);
        self::assertSame(4.0, $rows[1]->shareBar);
    }

    public function testFromTableWidgetTreatsMissingParallelArraysAsEmpty(): void
    {
        $rows = RankingTableRows::fromTableWidget([
            'rows' => [
                ['2', 'Only label'],
            ],
        ], static fn (): string => '/unused');

        self::assertSame('2', $rows[0]->rank);
        self::assertSame('Only label', $rows[0]->label);
        self::assertSame('', $rows[0]->count);
        self::assertSame('', $rows[0]->share);
        self::assertNull($rows[0]->labelHref);
        self::assertNull($rows[0]->action);
        self::assertNull($rows[0]->shareBar);
    }

    #[DataProvider('rejectedAnalysisKeys')]
    public function testFromTableWidgetRejectsEachAnalysisKey(string $key, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($key);

        RankingTableRows::fromTableWidget([
            'rows' => [],
            $key => $value,
        ], static fn (): string => '/unused');
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function rejectedAnalysisKeys(): iterable
    {
        yield 'summary' => ['summaryStats', ['meanDisplay' => '1']];
        yield 'footer' => ['footerRow', ['count' => '1']];
    }

    public function testFromTableWidgetRejectsNonListRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rows must be a list');

        RankingTableRows::fromTableWidget([
            'rows' => 'not-a-list',
        ], static fn (): string => '/unused');
    }

    public function testFromTableWidgetRejectsANonListRow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('list of cells');

        RankingTableRows::fromTableWidget([
            'rows' => ['not-a-row'],
        ], static fn (): string => '/unused');
    }

    public function testRankShiftCellClassFollowsEnteredAndDirection(): void
    {
        self::assertSame('stats-rank-shift-new', new RankingTableRankShift(entered: true, rankDelta: 2)->cellClass());
        self::assertSame('stats-rank-shift-up', new RankingTableRankShift(rankDelta: 1)->cellClass());
        self::assertSame('stats-rank-shift-down', new RankingTableRankShift(rankDelta: -4)->cellClass());
        self::assertSame('', new RankingTableRankShift(rankDelta: 0)->cellClass());
        self::assertSame('', new RankingTableRankShift()->cellClass());
    }
}
