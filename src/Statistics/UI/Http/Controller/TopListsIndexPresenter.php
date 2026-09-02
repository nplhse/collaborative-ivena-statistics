<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class TopListsIndexPresenter
{
    public function __construct(
        private TopListDefinitionRegistry $topListDefinitionRegistry,
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
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

        return new TopListsIndexViewModel($cards);
    }
}
