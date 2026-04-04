<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Application\Panel\PanelDefinition;

final readonly class DistributionPageConfig
{
    public const string REQUEST_ATTRIBUTE = '_distribution_page';

    /**
     * @param list<PanelDefinition> $panels
     */
    public function __construct(
        public string $routeName,
        public array $panels,
        public ?string $defaultPanelKey = null,
    ) {
    }

    /**
     * @return array<string, PanelDefinition>
     */
    public function panelsByKey(): array
    {
        $map = [];
        foreach ($this->panels as $panel) {
            $map[$panel->key] = $panel;
        }

        return $map;
    }

    public function getPanel(string $key): ?PanelDefinition
    {
        return $this->panelsByKey()[$key] ?? null;
    }

    public function defaultPanel(): PanelDefinition
    {
        if (\is_string($this->defaultPanelKey)) {
            $p = $this->getPanel($this->defaultPanelKey);
            if ($p instanceof PanelDefinition) {
                return $p;
            }
        }

        return $this->panels[0];
    }
}
