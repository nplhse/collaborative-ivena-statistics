<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\TopList\TopListDefinitionInterface;

final readonly class TopListsPageViewModel
{
    /**
     * @param list<TopListDefinitionInterface> $topListDefinitions
     * @param array<string, string>            $topListSelectUrls
     */
    public function __construct(
        public StatisticWidget $topListWidget,
        public array $topListDefinitions,
        public string $currentTopListKey,
        public array $topListSelectUrls,
        public int $currentLimit,
        public string $headerTitleKey,
        public string $headerSubtitleKey,
    ) {
    }
}
