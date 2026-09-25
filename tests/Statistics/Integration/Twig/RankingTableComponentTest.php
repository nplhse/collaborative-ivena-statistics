<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Twig;

use App\Statistics\UI\Twig\RankingTable\RankingTableAction;
use App\Statistics\UI\Twig\RankingTable\RankingTableRankShift;
use App\Statistics\UI\Twig\RankingTable\RankingTableRow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class RankingTableComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTopListRowWithShareBarAndInsightAction(): void
    {
        $html = $this->render([
            'showShareBar' => true,
            'rows' => [
                new RankingTableRow(
                    rank: '1',
                    label: 'ACS',
                    count: '120',
                    share: '12,3%',
                    labelHref: '/explore/acs',
                    shareBar: 12.3,
                    action: new RankingTableAction(
                        '/insights/acs',
                        'tabler:chart-bar',
                        'stats.insights.open',
                        'stats-top-lists-row-insight',
                    ),
                ),
            ],
        ]);

        self::assertStringContainsString('href="/explore/acs"', $html);
        self::assertStringContainsString('ACS', $html);
        self::assertStringContainsString('120', $html);
        self::assertStringContainsString('12,3%', $html);
        self::assertStringContainsString('data-testid="stats-top-lists-share-bar"', $html);
        self::assertStringContainsString('width: 12.3%', $html);
        self::assertStringContainsString('data-testid="stats-top-lists-row-insight"', $html);
        self::assertStringContainsString('href="/insights/acs"', $html);
        self::assertStringNotContainsString('card-footer', $html);
        self::assertStringNotContainsString('stats-top-lists-delta-count', $html);
    }

    public function testRendersComparisonSideCellsWithoutAComparisonFlag(): void
    {
        $html = $this->render([
            'rows' => [
                new RankingTableRow(
                    rank: '2',
                    label: 'ACS',
                    count: '80',
                    share: '8,0%',
                    rankShift: new RankingTableRankShift(rankDelta: 3),
                    countDelta: 5,
                    shareDelta: -1.5,
                    testId: 'stats-top-lists-comparison-table-b-row-acs',
                ),
            ],
        ]);

        self::assertStringContainsString('data-testid="stats-top-lists-comparison-table-b-row-acs"', $html);
        self::assertStringContainsString('stats-rank-shift-up', $html);
        self::assertStringContainsString('data-testid="stats-top-lists-rank-badge"', $html);
        self::assertStringContainsString('data-testid="stats-top-lists-delta-count"', $html);
        self::assertStringContainsString('data-testid="stats-top-lists-delta-share"', $html);
        self::assertStringContainsString('+5', $html);
        self::assertStringContainsString('-1,5%', $html);
        self::assertStringNotContainsString('stats-top-lists-share-bar', $html);
        self::assertStringNotContainsString('card-footer', $html);
    }

    public function testRendersContextLinePlainLabelAndAMissingShareBar(): void
    {
        $html = $this->render([
            'showShareBar' => true,
            'shareBarTestId' => 'stats-insights-share-bar',
            'rows' => [
                new RankingTableRow(
                    rank: '3',
                    label: 'Group',
                    count: '4',
                    share: '1,0%',
                    labelContext: 'Cardiology',
                ),
                new RankingTableRow(
                    rank: '4',
                    label: 'Linked',
                    count: '1',
                    share: '0,5%',
                    labelHref: '/insights/linked',
                    action: new RankingTableAction(
                        '/explore/linked',
                        'tabler:book-2',
                        'stats.indication.nav.catalog',
                        'stats-insights-row-explore',
                    ),
                ),
            ],
        ]);

        self::assertStringContainsString('Cardiology', $html);
        self::assertStringContainsString('text-secondary small', $html);
        self::assertStringNotContainsString('href="">Group', $html);
        self::assertStringContainsString('href="/insights/linked"', $html);
        self::assertStringContainsString('width: 0%', $html);
        self::assertStringContainsString('data-testid="stats-insights-share-bar"', $html);
        self::assertStringContainsString('data-testid="stats-insights-row-explore"', $html);
    }

    public function testRendersNewAndDownRankShifts(): void
    {
        $html = $this->render([
            'rows' => [
                new RankingTableRow(
                    rank: '—',
                    label: 'New',
                    count: '1',
                    share: '1,0%',
                    rankShift: new RankingTableRankShift(entered: true),
                ),
                new RankingTableRow(
                    rank: '5',
                    label: 'Down',
                    count: '2',
                    share: '2,0%',
                    rankShift: new RankingTableRankShift(rankDelta: -2),
                ),
            ],
        ]);

        self::assertStringContainsString('stats-rank-shift-new', $html);
        self::assertStringContainsString('stats-rank-shift-down', $html);
    }

    public function testComparisonLayoutKeepsValuePairsAndCompactTableWithoutDeltas(): void
    {
        $html = $this->render([
            'compact' => true,
            'valuePairs' => true,
            'rows' => [
                new RankingTableRow(
                    rank: '1',
                    label: 'ACS',
                    count: '10',
                    share: '10,0%',
                ),
            ],
        ]);

        self::assertStringContainsString('table-sm', $html);
        self::assertSame(3, substr_count($html, 'stats-top-lists-value-pair'));
        self::assertStringNotContainsString('stats-top-lists-delta-count', $html);
        self::assertStringNotContainsString('stats-top-lists-rank-badge', $html);
    }

    public function testEmptyRowsWithoutAMessageRenderNothing(): void
    {
        $html = $this->render([
            'rows' => [],
        ]);

        self::assertSame('', trim($html));
    }

    public function testEmptyRowsShowTheHintWithoutAFooter(): void
    {
        $html = $this->render([
            'rows' => [],
            'emptyMessage' => 'No ranking rows',
        ]);

        self::assertStringContainsString('No ranking rows', $html);
        self::assertStringContainsString('text-muted', $html);
        self::assertStringNotContainsString('<table', $html);
        self::assertStringNotContainsString('card-footer', $html);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        return (string) $this->renderTwigComponent('Statistics:RankingTable', $props + [
            'rankHeader' => 'Rank',
            'labelHeader' => 'Diagnosis',
            'countHeader' => 'Count',
            'shareHeader' => 'Share',
        ]);
    }
}
