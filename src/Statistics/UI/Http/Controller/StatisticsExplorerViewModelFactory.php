<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Report\ReportDefinitionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsExplorerViewModelFactory
{
    public function __construct(
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
        ?string $currentReportKey = null,
    ): array {
        if ('dashboard' === $currentPage) {
            return [];
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

    /**
     * @param list<array{key:string,labelKey:string,url:string,active:bool}> $entries
     */
    private function sortEntriesByTranslatedLabel(array &$entries, string $locale): void
    {
        usort(
            $entries,
            fn (array $left, array $right): int => strcmp(
                mb_strtolower($this->translator->trans($left['labelKey'], [], 'statistics', $locale)),
                mb_strtolower($this->translator->trans($right['labelKey'], [], 'statistics', $locale)),
            ),
        );
    }
}
