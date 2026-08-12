<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsExplorerViewModelFactory
{
    public function __construct(
        private TopListDefinitionRegistry $topListDefinitionRegistry,
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
        ?string $currentTopListKey = null,
    ): array {
        if ('dashboard' === $currentPage) {
            return [];
        }

        $topListEntries = [];
        foreach ($this->topListDefinitionRegistry->all() as $definition) {
            $topListEntries[] = [
                'key' => $definition->key(),
                'labelKey' => $definition->labelTranslationKey(),
                'url' => $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_top_lists',
                    [StatisticsQueryKeys::REPORT => $definition->key()],
                ),
                'active' => $currentTopListKey === $definition->key(),
            ];
        }
        $this->sortEntriesByTranslatedLabel($topListEntries, $request->getLocale());

        return [[
            'key' => 'top_lists',
            'labelKey' => 'stats.top_lists.select_label',
            'entries' => $topListEntries,
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
