<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionRegistry;
use App\Statistics\Application\Report\ReportDefinitionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsExplorerViewModelFactory
{
    public function __construct(
        private AnalysisDefinitionRegistry $analysisDefinitionRegistry,
        private ReportDefinitionRegistry $reportDefinitionRegistry,
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{
     *   key:string,
     *   labelKey:string,
     *   entries:list<array{key:string,labelKey:string,url:string,active:bool}>
     * }>
     */
    public function create(
        Request $request,
        string $currentPage,
        ?string $currentAnalysisKey = null,
        ?string $currentReportKey = null,
    ): array {
        if ('dashboard' === $currentPage) {
            return [];
        }

        if ('analysis' === $currentPage) {
            $analysisEntries = [];
            foreach ($this->analysisDefinitionRegistry->all() as $definition) {
                if ($this->isLegacyKey($definition->key())) {
                    continue;
                }
                $analysisEntries[] = [
                    'key' => $definition->key(),
                    'labelKey' => $definition->labelTranslationKey(),
                    'url' => $this->statisticsNavigationUrlBuilder->build(
                        $request,
                        'app_stats_analysis',
                        [StatisticsQueryKeys::ANALYSIS => $definition->key()],
                        $definition->isPivotLike() ? StatisticsQueryKeys::PIVOT_STALE : StatisticsQueryKeys::CHART_TABLE_STALE,
                    ),
                    'active' => $currentAnalysisKey === $definition->key(),
                ];
            }
            $this->sortEntriesByTranslatedLabel($analysisEntries, $request->getLocale());

            return [[
                'key' => 'analysis',
                'labelKey' => 'stats.analysis.select_label',
                'entries' => $analysisEntries,
            ]];
        }

        $reportEntries = [];
        foreach ($this->reportDefinitionRegistry->all() as $definition) {
            $reportEntries[] = [
                'key' => $definition->key(),
                'labelKey' => $definition->labelTranslationKey(),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_reports',
                    [StatisticsQueryKeys::REPORT => $definition->key()],
                ),
                'active' => $currentReportKey === $definition->key(),
            ];
        }
        $this->sortEntriesByTranslatedLabel($reportEntries, $request->getLocale());

        return [[
            'key' => 'reports',
            'labelKey' => 'stats.reports.select_label',
            'entries' => $reportEntries,
        ]];
    }

    private function isLegacyKey(string $key): bool
    {
        return 'pivot' === $key || 'allocations_over_time' === $key;
    }

    /**
     * @param list<array{key:string,labelKey:string,url:string,active:bool}> $entries
     */
    private function sortEntriesByTranslatedLabel(array &$entries, string $locale): void
    {
        usort(
            $entries,
            fn (array $left, array $right): int => strcmp(
                mb_strtolower($this->translator->trans($left['labelKey'], [], null, $locale)),
                mb_strtolower($this->translator->trans($right['labelKey'], [], null, $locale)),
            ),
        );
    }
}
