<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TopListsIndexPresenter
{
    public function __construct(
        private TopListDefinitionRegistry $topListDefinitionRegistry,
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    public function present(Request $request): TopListsIndexViewModel
    {
        $cards = [];
        foreach ($this->topListDefinitionRegistry->all() as $definition) {
            $cards[] = new TopListsIndexCardViewModel(
                $definition->key(),
                $definition->labelTranslationKey(),
                $definition->descriptionTranslationKey(),
                $definition->icon(),
                $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    'app_stats_top_lists_show',
                    ['report' => $definition->key()],
                ),
            );
        }
        $this->sortCardsByTranslatedLabel($cards, $request->getLocale());

        return new TopListsIndexViewModel($cards);
    }

    /**
     * @param list<TopListsIndexCardViewModel> $cards
     */
    private function sortCardsByTranslatedLabel(array &$cards, string $locale): void
    {
        usort(
            $cards,
            fn (TopListsIndexCardViewModel $left, TopListsIndexCardViewModel $right): int => strcmp(
                mb_strtolower($this->translator->trans($left->labelKey, [], 'statistics', $locale)),
                mb_strtolower($this->translator->trans($right->labelKey, [], 'statistics', $locale)),
            ),
        );
    }
}
