<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\Domain\Model\TransportTimeStatsView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'TransportTimeTable', template: '@Statistics/components/TransportTimeTable.html.twig')]
final class TransportTimeTable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public TransportTimeStatsView $viewModel;

    /**
     * View mode: "int" for counts, "pct" for percentages.
     */
    public string $view = 'int';
}
