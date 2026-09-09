<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Statistics\GenericAnalysis\Application\Export\TabularExportColumn;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TopListExportBuilder
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function build(
        TopListDefinitionInterface $definition,
        TopListRanking $ranking,
        ?TopListComparison $comparison,
        string $scopeA,
        string $periodA,
        ?string $scopeB = null,
        ?string $periodB = null,
    ): TabularExportDocument {
        if ($comparison instanceof TopListComparison) {
            return $this->buildComparisonDocument(
                $definition,
                $comparison,
                $scopeA,
                $periodA,
                $scopeB ?? '',
                $periodB ?? '',
            );
        }

        return $this->buildRankingDocument($definition, $ranking, $scopeA, $periodA);
    }

    public function filenameTitle(TopListDefinitionInterface $definition, TopListLimit $limit, bool $compare): string
    {
        $parts = [
            $definition->key(),
            $limit->isAll ? TopListLimit::ALL : 'top-'.$limit->queryValue(),
        ];
        if ($compare) {
            $parts[] = 'comparison';
        }

        return implode(' ', $parts);
    }

    private function buildRankingDocument(
        TopListDefinitionInterface $definition,
        TopListRanking $ranking,
        string $scopeA,
        string $periodA,
    ): TabularExportDocument {
        $headers = [
            new TabularExportColumn('scope', $this->trans('stats.filter.scope_label')),
            new TabularExportColumn('period', $this->trans('stats.filter.period_label')),
            new TabularExportColumn('rank', $this->trans('stats.top_lists.table.rank')),
            new TabularExportColumn('label', $this->trans($definition->tableLabelColumnTranslationKey())),
            new TabularExportColumn('count', $this->trans('stats.top_lists.table.count')),
            new TabularExportColumn('share', $this->trans('stats.top_lists.table.share')),
        ];

        $rows = [];
        foreach ($ranking->rows as $row) {
            $rows[] = [
                $scopeA,
                $periodA,
                $row->rank,
                $row->label,
                $row->count,
                $row->share,
            ];
        }

        return new TabularExportDocument($headers, $rows);
    }

    private function buildComparisonDocument(
        TopListDefinitionInterface $definition,
        TopListComparison $comparison,
        string $scopeA,
        string $periodA,
        string $scopeB,
        string $periodB,
    ): TabularExportDocument {
        $headers = [
            new TabularExportColumn('scope_a', $this->trans('stats.top_lists.export.scope_a')),
            new TabularExportColumn('period_a', $this->trans('stats.top_lists.export.period_a')),
            new TabularExportColumn('scope_b', $this->trans('stats.top_lists.export.scope_b')),
            new TabularExportColumn('period_b', $this->trans('stats.top_lists.export.period_b')),
            new TabularExportColumn('label', $this->trans($definition->tableLabelColumnTranslationKey())),
            new TabularExportColumn('rank_a', $this->trans('stats.top_lists.comparison.rank_a')),
            new TabularExportColumn('count_a', $this->trans('stats.top_lists.comparison.count_a')),
            new TabularExportColumn('share_a', $this->trans('stats.top_lists.comparison.share_a')),
            new TabularExportColumn('rank_b', $this->trans('stats.top_lists.comparison.rank_b')),
            new TabularExportColumn('count_b', $this->trans('stats.top_lists.comparison.count_b')),
            new TabularExportColumn('share_b', $this->trans('stats.top_lists.comparison.share_b')),
            new TabularExportColumn('delta_count', $this->trans('stats.top_lists.comparison.delta_count')),
            new TabularExportColumn('delta_share', $this->trans('stats.top_lists.comparison.delta_share')),
            new TabularExportColumn('relative_delta', $this->trans('stats.top_lists.export.relative_delta')),
            new TabularExportColumn('rank_movement', $this->trans('stats.top_lists.comparison.rank_movement')),
        ];

        $rows = [];
        foreach ($comparison->mergedRows() as $row) {
            $rows[] = [
                $scopeA,
                $periodA,
                $scopeB,
                $periodB,
                $row->label,
                $row->rankA,
                $row->countA,
                $row->shareA,
                $row->rankB,
                $row->countB,
                $row->shareB,
                $row->deltaCount,
                $row->deltaShare,
                $this->relativeDelta($row->countA, $row->countB),
                $row->rankMovement,
            ];
        }

        return new TabularExportDocument($headers, $rows);
    }

    private function relativeDelta(?int $countA, ?int $countB): ?float
    {
        if (null === $countA || null === $countB || 0 === $countA) {
            return null;
        }

        return ($countB - $countA) / $countA;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], 'statistics');
    }
}
