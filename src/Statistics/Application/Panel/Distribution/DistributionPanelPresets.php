<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Application\Panel\PanelDefinition;

/**
 * Wiederkehrende PanelDefinition-Bausteine für Distribution-Seiten (explizit in Controllern zusammensetzen).
 */
final class DistributionPanelPresets
{
    public static function urgency(): PanelDefinition
    {
        return new PanelDefinition(
            key: 'urgency',
            type: 'distribution',
            dimensionKind: DimensionKind::Column,
            dimensionField: 'urgency_code',
            dimensionLabel: 'statistics.distribution.dim.urgency',
            groupByField: 'hospital_tier_code',
            groupByLabel: 'statistics.distribution.dim.hospital_tier',
            filters: ['date_range', 'hospital_tier', 'hospital_location'],
            options: ['default_view' => 'grouped', 'show_percent' => true],
            controls: ['allow_view_mode_toggle' => true, 'allow_group_by' => true],
            filterDefaults: [
                'date_range' => 'all_cases',
                'hospital_tier' => [],
                'hospital_location' => [],
            ],
        );
    }

    public static function gender(): PanelDefinition
    {
        return new PanelDefinition(
            key: 'gender',
            type: 'distribution',
            dimensionKind: DimensionKind::Column,
            dimensionField: 'gender_code',
            dimensionLabel: 'statistics.distribution.dim.gender',
            groupByField: 'hospital_tier_code',
            groupByLabel: 'statistics.distribution.dim.hospital_tier',
            filters: ['date_range', 'hospital_tier', 'hospital_location'],
            options: ['default_view' => 'grouped', 'show_percent' => true],
            controls: ['allow_view_mode_toggle' => true, 'allow_group_by' => true],
            filterDefaults: [
                'date_range' => 'all_cases',
                'hospital_tier' => [],
                'hospital_location' => [],
            ],
        );
    }

    public static function ageCohort(): PanelDefinition
    {
        return new PanelDefinition(
            key: 'age_cohort',
            type: 'distribution',
            dimensionKind: DimensionKind::AgeCohort,
            dimensionField: '',
            dimensionLabel: 'statistics.distribution.dim.age_cohort',
            groupByField: 'hospital_tier_code',
            groupByLabel: 'statistics.distribution.dim.hospital_tier',
            filters: ['date_range', 'hospital_tier', 'hospital_location'],
            options: ['default_view' => 'grouped', 'show_percent' => true],
            controls: ['allow_view_mode_toggle' => true, 'allow_group_by' => true],
            filterDefaults: [
                'date_range' => 'all_cases',
                'hospital_tier' => [],
                'hospital_location' => [],
            ],
        );
    }
}
