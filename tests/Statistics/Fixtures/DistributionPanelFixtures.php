<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Fixtures;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionPageConfigResolver;
use App\Statistics\Application\Panel\Distribution\TransportTimeBucketExpression;
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
            'controls' => [
                'allow_view_mode_toggle' => true,
                'allow_group_by' => true,
                'allow_bar_basis_average' => true,
            ],
            'average_metric' => 'age',
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
            'controls' => [
                'allow_view_mode_toggle' => true,
                'allow_group_by' => true,
                'allow_bar_basis_average' => true,
            ],
            'average_metric' => 'age',
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
    public static function genderPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'gender',
            'dimension_field' => 'gender_code',
            'dimension_label' => 'statistics.distribution.dim.gender',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function createdHourPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'hour',
            'dimension_field' => 'created_hour',
            'dimension_label' => 'statistics.distribution.dim.hour',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function weekdayPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'weekday',
            'dimension_field' => 'created_weekday',
            'dimension_label' => 'statistics.distribution.dim.weekday',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function assignmentPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'assignment',
            'dimension_field' => 'assignment_id',
            'dimension_label' => 'statistics.distribution.dim.assignment',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function occasionPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'occasion',
            'dimension_field' => 'COALESCE(occasion_id, 0)',
            'dimension_label' => 'statistics.distribution.dim.occasion',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function requiresCathlabPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'requires_cathlab',
            'dimension_field' => '(CASE WHEN requires_cathlab IS NULL THEN 0 WHEN requires_cathlab = false THEN 1 ELSE 2 END)',
            'dimension_label' => 'statistics.distribution.dim.requires_cathlab',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function isCprPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'is_cpr',
            'dimension_field' => '(CASE WHEN is_cpr IS NULL THEN 0 WHEN is_cpr = false THEN 1 ELSE 2 END)',
            'dimension_label' => 'statistics.distribution.dim.is_cpr',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function transportTimeBucketPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'transport_time_bucket',
            'dimension_field' => TransportTimeBucketExpression::sql('transport_time_minutes'),
            'dimension_label' => 'statistics.distribution.dim.transport_time_bucket',
            'average_metric' => 'transport_time_minutes',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function requiresResusPanelOptions(): array
    {
        return array_replace(self::urgencyPanelOptions(), [
            'key' => 'requires_resus',
            'dimension_field' => '(CASE WHEN requires_resus IS NULL THEN 0 WHEN requires_resus = false THEN 1 ELSE 2 END)',
            'dimension_label' => 'statistics.distribution.dim.requires_resus',
        ]);
    }

    public static function gender(): PanelDefinition
    {
        return new DistributionPageConfigResolver()->createPanelDefinition(self::genderPanelOptions());
    }

    public static function createdHour(): PanelDefinition
    {
        return new DistributionPageConfigResolver()->createPanelDefinition(self::createdHourPanelOptions());
    }

    public static function transportTimeBucket(): PanelDefinition
    {
        return new DistributionPageConfigResolver()->createPanelDefinition(self::transportTimeBucketPanelOptions());
    }

    public static function requiresResus(): PanelDefinition
    {
        return new DistributionPageConfigResolver()->createPanelDefinition(self::requiresResusPanelOptions());
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
