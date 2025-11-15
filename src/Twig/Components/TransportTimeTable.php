<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\TransportTimeStatsView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'TransportTimeTable')]
final class TransportTimeTable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public TransportTimeStatsView $viewModel;

    /**
     * View mode: "int" for counts, "pct" for percentages.
     */
    public string $view = 'int';
}
