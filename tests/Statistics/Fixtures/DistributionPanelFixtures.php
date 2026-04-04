<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Fixtures;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Statistics\Application\Panel\PanelDefinition;

final class DistributionPanelFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function urgencyPanelOptions(): array
    {
        return [
            'key' => 'urgency',
            'type' => 'distribution',
            'dimension_kind' => DimensionKind::Column->value,
            'dimension_field' => 'urgency_code',
            'dimension_label' => 'statistics.distribution.dim.urgency',
            'group_by_field' => 'hospital_tier_code',
            'group_by_label' => 'statistics.distribution.dim.hospital_tier',
            'filters' => ['date_range', 'hospital_tier', 'hospital_location'],
            'options' => ['default_view' => 'grouped', 'show_percent' => true],
            'controls' => ['allow_view_mode_toggle' => true, 'allow_group_by' => true],
            'filter_defaults' => [
                'date_range' => 'all_cases',
                'hospital_tier' => [],
                'hospital_location' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ageCohortPanelOptions(): array
    {
        return [
            'key' => 'age_cohort',
            'type' => 'distribution',
            'dimension_kind' => DimensionKind::AgeCohort->value,
            'dimension_field' => '',
            'dimension_label' => 'statistics.distribution.dim.age_cohort',
            'group_by_field' => 'hospital_tier_code',
            'group_by_label' => 'statistics.distribution.dim.hospital_tier',
            'filters' => ['date_range', 'hospital_tier', 'hospital_location'],
            'options' => ['default_view' => 'absolute', 'show_percent' => false],
            'controls' => ['allow_view_mode_toggle' => true, 'allow_group_by' => true],
            'filter_defaults' => [
                'date_range' => 'all_cases',
                'hospital_tier' => [],
                'hospital_location' => [],
            ],
        ];
    }

    public static function urgency(): PanelDefinition
    {
        return new DistributionPageConfigResolver()->createPanelDefinition(self::urgencyPanelOptions());
    }

    public static function ageCohort(): PanelDefinition
    {
        return new DistributionPageConfigResolver()->createPanelDefinition(self::ageCohortPanelOptions());
    }

    /**
     * @return array<string, mixed>
     */
    public static function sampleUrgencyPageOptions(): array
    {
        return [
            'route_name' => 'app_stats_distribution_urgency',
            'section_key' => 'urgency',
            'panels' => [self::urgencyPanelOptions()],
        ];
    }
}
