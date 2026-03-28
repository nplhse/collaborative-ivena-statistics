<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\Domain\Model\DistributionPanelView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @psalm-suppress MissingConstructor Populated by {@see mount()}.
 */
#[AsTwigComponent(name: 'DistributionPanel', template: '@Statistics/components/DistributionPanel.html.twig')]
final class DistributionPanel
{
    public DistributionPanelView $view;

    /** @var array<string, mixed> */
    public array $chartOptions = [];

    public string $chartOptionsJson = '{}';

    public bool $chartUsesPercentYAxis = false;

    /**
     * @param array<string, mixed> $chartOptions
     */
    public function mount(DistributionPanelView $view, array $chartOptions = [], bool $chartUsesPercentYAxis = false): void
    {
        $this->view = $view;
        $this->chartOptions = $chartOptions;
        $this->chartUsesPercentYAxis = $chartUsesPercentYAxis;
    }

    #[PostMount]
    public function encodeChartOptions(): void
    {
        $json = json_encode($this->chartOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->chartOptionsJson = \is_string($json) ? $json : '{}';
    }
}
