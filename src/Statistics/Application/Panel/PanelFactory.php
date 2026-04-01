<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel;

final class PanelFactory
{
    public function createDistributionPanel(string $panelKey): PanelDefinition
    {
        $panels = $this->distributionPanels();

        if (!isset($panels[$panelKey])) {
            return $panels['urgency'];
        }

        return $panels[$panelKey];
    }

    /**
     * @return list<PanelDefinition>
     */
    public function listDistributionPanels(): array
    {
        return array_values($this->distributionPanels());
    }

    /**
     * @return array<string, PanelDefinition>
     */
    private function distributionPanels(): array
    {
        return [
            'urgency' => new PanelDefinition(
                key: 'urgency',
                type: 'distribution',
                dimensionField: 'urgency_code',
                dimensionLabel: 'statistics.distribution.dim.urgency',
                groupByField: 'hospital_tier_code',
                groupByLabel: 'statistics.distribution.dim.hospital_tier',
                filters: ['date_range'],
                options: ['default_view' => 'grouped', 'show_percent' => true],
                controls: ['allow_view_mode_toggle' => true],
            ),
            'gender' => new PanelDefinition(
                key: 'gender',
                type: 'distribution',
                dimensionField: 'gender_code',
                dimensionLabel: 'statistics.distribution.dim.gender',
                groupByField: 'hospital_tier_code',
                groupByLabel: 'statistics.distribution.dim.hospital_tier',
                filters: ['date_range'],
                options: ['default_view' => 'grouped', 'show_percent' => true],
                controls: ['allow_view_mode_toggle' => true],
            ),
        ];
    }
}
