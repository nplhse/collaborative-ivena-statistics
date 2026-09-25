<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\RankingTable;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\Insights\InsightValueRow;
use App\Statistics\Application\TopList\TopListComparisonRow;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class RankingTableRows
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $urlBuilder,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<RankingTableRow>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'ranking_table_widget_rows')]
    public function widgetRows(array $payload): array
    {
        return self::fromTableWidget($payload, $this->url(...));
    }

    /**
     * @param list<TopListComparisonRow> $rows
     *
     * @return list<RankingTableRow>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'ranking_table_comparison_rows')]
    public function comparisonRows(array $rows, bool $showDiff, string $rowTestIdPrefix): array
    {
        return self::fromComparisonSide($rows, $showDiff, $rowTestIdPrefix, $this->url(...));
    }

    /**
     * @param list<InsightValueRow> $rows
     *
     * @return list<RankingTableRow>
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'ranking_table_insight_rows')]
    public function fromInsightValues(array $rows, string $actionTestId): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $exploreUrl = $row->exploreUrl;
            $mapped[] = new RankingTableRow(
                rank: null === $row->rank ? '' : (string) $row->rank,
                label: $row->label,
                count: number_format($row->count, 0, ',', '.'),
                share: $row->shareDisplay ?? '',
                labelHref: $row->url,
                labelContext: $row->contextLabel,
                action: null === $exploreUrl || '' === $exploreUrl
                    ? null
                    : new RankingTableAction(
                        $exploreUrl,
                        'tabler:book-2',
                        'stats.indication.nav.catalog',
                        $actionTestId,
                    ),
            );
        }

        return $mapped;
    }

    /**
     * Maps a Top List {@see \App\Statistics\Application\DTO\StatisticWidget} table payload.
     * Analysis tables (month links, summary, footer row) are rejected.
     *
     * @param array<string, mixed>                              $payload
     * @param callable(StatisticWidgetNavigationTarget): string $url
     *
     * @return list<RankingTableRow>
     */
    public static function fromTableWidget(array $payload, callable $url): array
    {
        foreach (['monthRowTargets', 'summaryStats', 'footerRow'] as $key) {
            if (\array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException(sprintf('Ranking tables do not render analysis payload key "%s".', $key));
            }
        }

        $sourceRows = $payload['rows'] ?? [];
        if (!\is_array($sourceRows)) {
            throw new \InvalidArgumentException('Ranking table payload rows must be a list.');
        }

        $labelTargets = self::targetList($payload['labelRowTargets'] ?? []);
        $insightTargets = self::targetList($payload['insightRowTargets'] ?? []);
        $shareBars = \is_array($payload['shareBars'] ?? null) ? $payload['shareBars'] : [];

        $mapped = [];
        foreach (array_values($sourceRows) as $index => $sourceRow) {
            if (!\is_array($sourceRow)) {
                throw new \InvalidArgumentException('Ranking table payload rows must be a list of cells.');
            }

            $cells = array_values($sourceRow);
            $labelTarget = $labelTargets[$index] ?? null;
            $insightTarget = $insightTargets[$index] ?? null;
            $shareBar = $shareBars[$index] ?? null;

            $mapped[] = new RankingTableRow(
                rank: self::cell($cells, 0),
                label: self::cell($cells, 1),
                count: self::cell($cells, 2),
                share: self::cell($cells, 3),
                labelHref: $labelTarget instanceof StatisticWidgetNavigationTarget ? $url($labelTarget) : null,
                shareBar: is_numeric($shareBar) ? (float) $shareBar : null,
                action: $insightTarget instanceof StatisticWidgetNavigationTarget
                    ? new RankingTableAction(
                        $url($insightTarget),
                        'tabler:chart-bar',
                        'stats.insights.open',
                        'stats-top-lists-row-insight',
                    )
                    : null,
            );
        }

        return $mapped;
    }

    /**
     * @param list<TopListComparisonRow>                        $rows
     * @param callable(StatisticWidgetNavigationTarget): string $url
     *
     * @return list<RankingTableRow>
     */
    public static function fromComparisonSide(array $rows, bool $showDiff, string $rowTestIdPrefix, callable $url): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $rank = $showDiff ? $row->rankB : $row->rankA;
            $count = $showDiff ? $row->countB : $row->countA;
            $share = $showDiff ? $row->shareB : $row->shareA;
            $labelTarget = $row->labelTarget;

            $mapped[] = new RankingTableRow(
                rank: null === $rank ? '—' : (string) $rank,
                label: $row->label,
                count: null === $count ? '—' : (string) $count,
                share: null === $share ? '—' : number_format($share, 1, ',', '.').'%',
                labelHref: $labelTarget instanceof StatisticWidgetNavigationTarget ? $url($labelTarget) : null,
                rankShift: $showDiff ? new RankingTableRankShift(entered: $row->onlyInB, rankDelta: $row->rankMovement) : null,
                countDelta: $showDiff && !$row->onlyInB ? $row->deltaCount : null,
                shareDelta: $showDiff && !$row->onlyInB ? $row->deltaShare : null,
                testId: $rowTestIdPrefix.'-row-'.$row->identity,
            );
        }

        return $mapped;
    }

    private function url(StatisticWidgetNavigationTarget $target): string
    {
        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return '#';
        }

        return $this->urlBuilder->buildFromTarget($request, $target);
    }

    /**
     * @return list<mixed>
     */
    private static function targetList(mixed $targets): array
    {
        return \is_array($targets) ? array_values($targets) : [];
    }

    /**
     * @param list<mixed> $cells
     */
    private static function cell(array $cells, int $index): string
    {
        $value = $cells[$index] ?? '';
        if (\is_array($value) || \is_object($value)) {
            return '';
        }

        return (string) $value;
    }
}
