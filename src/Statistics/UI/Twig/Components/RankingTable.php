<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\UI\Twig\RankingTable\RankingTableRow;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'Statistics:RankingTable', template: '@Statistics/components/RankingTable.html.twig')]
final class RankingTable
{
    public string $rankHeader = '';

    public string $labelHeader = '';

    public string $countHeader = '';

    public string $shareHeader = '';

    /**
     * @var list<RankingTableRow>
     */
    public array $rows = [];

    public bool $showShareBar = false;

    public bool $compact = false;

    public bool $valuePairs = false;

    public ?string $emptyMessage = null;

    public string $shareBarTestId = 'stats-top-lists-share-bar';

    public string $countDeltaAriaKey = 'stats.top_lists.comparison.delta.aria';

    public string $shareDeltaAriaKey = 'stats.top_lists.comparison.delta.aria';

    public string $countDeltaTestId = 'stats-top-lists-delta-count';

    public string $shareDeltaTestId = 'stats-top-lists-delta-share';

    public function hasActions(): bool
    {
        return array_any($this->rows, fn (RankingTableRow $row): bool => $row->action instanceof \App\Statistics\UI\Twig\RankingTable\RankingTableAction);
    }
}
