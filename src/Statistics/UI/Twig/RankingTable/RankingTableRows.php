<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\RankingTable;

use App\Statistics\Application\DTO\StatisticWidgetNavigationTarget;
use App\Statistics\Application\Insights\InsightValueRow;

final readonly class RankingTableRows
{
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
