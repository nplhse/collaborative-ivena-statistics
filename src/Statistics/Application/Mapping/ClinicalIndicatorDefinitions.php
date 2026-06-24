<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

final class ClinicalIndicatorDefinitions
{
    public const string DIMENSION_RESOURCES = 'clinical_resources';
    public const string DIMENSION_FEATURES = 'clinical_features';

    public static function isUnpivotDimension(string $dimensionKey): bool
    {
        return \in_array($dimensionKey, [self::DIMENSION_RESOURCES, self::DIMENSION_FEATURES], true);
    }

    /**
     * @return list<ClinicalIndicatorDefinition>
     */
    public static function forDimension(string $dimensionKey): array
    {
        return match ($dimensionKey) {
            self::DIMENSION_RESOURCES => self::resources(),
            self::DIMENSION_FEATURES => self::features(),
            default => throw new \InvalidArgumentException(sprintf('Unknown clinical indicator dimension "%s".', $dimensionKey)),
        };
    }

    /**
     * @return list<string>
     */
    public static function bucketKeys(string $dimensionKey): array
    {
        return array_map(
            static fn (ClinicalIndicatorDefinition $definition): string => $definition->bucketKey,
            self::forDimension($dimensionKey),
        );
    }

    public static function crossJoinValuesSql(string $dimensionKey, string $indicatorAlias = 'i'): string
    {
        $values = array_map(
            static fn (ClinicalIndicatorDefinition $definition): string => sprintf("('%s')", $definition->bucketKey),
            self::forDimension($dimensionKey),
        );

        return sprintf('(VALUES %s) AS %s(indicator_key)', implode(', ', $values), $indicatorAlias);
    }

    public static function indicatorMatchCaseExpression(
        string $allocationAlias = 'a',
        string $indicatorAlias = 'i',
    ): string {
        $branches = [];
        foreach (self::forDimension(self::DIMENSION_RESOURCES) as $definition) {
            $branches[] = self::matchBranch($definition, $allocationAlias);
        }
        foreach (self::forDimension(self::DIMENSION_FEATURES) as $definition) {
            $branches[] = self::matchBranch($definition, $allocationAlias);
        }

        return sprintf('CASE %s.indicator_key %s END', $indicatorAlias, implode(' ', $branches));
    }

    /**
     * @return list<ClinicalIndicatorDefinition>
     */
    private static function resources(): array
    {
        return [
            new ClinicalIndicatorDefinition(
                bucketKey: 'resus',
                labelTranslationKey: 'statistics.distribution.dim.requires_resus',
                matchSqlCondition: 'requires_resus IS TRUE',
                overviewCountKey: 'resus',
            ),
            new ClinicalIndicatorDefinition(
                bucketKey: 'cathlab',
                labelTranslationKey: 'statistics.distribution.dim.requires_cathlab',
                matchSqlCondition: 'requires_cathlab IS TRUE',
                overviewCountKey: 'cathlab',
            ),
        ];
    }

    /**
     * @return list<ClinicalIndicatorDefinition>
     */
    private static function features(): array
    {
        return [
            new ClinicalIndicatorDefinition(
                bucketKey: 'with_physician',
                labelTranslationKey: 'statistics.distribution.dim.is_with_physician',
                matchSqlCondition: 'is_with_physician IS TRUE',
                overviewCountKey: 'with_physician',
            ),
            new ClinicalIndicatorDefinition(
                bucketKey: 'cpr',
                labelTranslationKey: 'statistics.distribution.dim.is_cpr',
                matchSqlCondition: 'is_cpr IS TRUE',
                overviewCountKey: 'cpr',
            ),
            new ClinicalIndicatorDefinition(
                bucketKey: 'ventilation',
                labelTranslationKey: 'statistics.distribution.dim.is_ventilated',
                matchSqlCondition: 'is_ventilated IS TRUE',
                overviewCountKey: 'ventilated',
            ),
            new ClinicalIndicatorDefinition(
                bucketKey: 'shock',
                labelTranslationKey: 'stats.analysis.feature.is_shock',
                matchSqlCondition: 'is_shock IS TRUE',
                overviewCountKey: 'shock',
            ),
            new ClinicalIndicatorDefinition(
                bucketKey: 'pregnancy',
                labelTranslationKey: 'stats.analysis.feature.is_pregnant',
                matchSqlCondition: 'is_pregnant IS TRUE',
                overviewCountKey: 'pregnant',
            ),
            new ClinicalIndicatorDefinition(
                bucketKey: 'work_accident',
                labelTranslationKey: 'stats.analysis.feature.is_work_accident',
                matchSqlCondition: 'is_work_accident IS TRUE',
                overviewCountKey: 'work_accident',
            ),
            new ClinicalIndicatorDefinition(
                bucketKey: 'infection',
                labelTranslationKey: 'field.infection',
                matchSqlCondition: 'infection_id IS NOT NULL',
                overviewCountKey: 'infectious',
            ),
        ];
    }

    private static function matchBranch(ClinicalIndicatorDefinition $definition, string $tableAlias): string
    {
        return sprintf(
            "WHEN '%s' THEN %s",
            $definition->bucketKey,
            self::qualifiedMatchCondition($definition->matchSqlCondition, $tableAlias),
        );
    }

    private static function qualifiedMatchCondition(string $condition, string $tableAlias): string
    {
        if (str_starts_with($condition, $tableAlias.'.')) {
            return $condition;
        }

        return $tableAlias.'.'.$condition;
    }
}
