<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

/**
 * Dimensions available as Analysis Explorer filters in the initial rollout.
 */
final class ExplorerAnalysisFilterCatalog
{
    /** @var list<string> */
    public const array ALLOWED_DIMENSION_KEYS = [
        'department',
        'speciality',
        'urgency',
        'transport_type',
        'gender',
        'age_group',
        'resus',
        'cpr',
        'ventilation',
        'assignment',
        'indication',
        'secondary_indication',
        'indication_group',
    ];

    public static function isAllowed(string $dimensionKey): bool
    {
        return \in_array($dimensionKey, self::ALLOWED_DIMENSION_KEYS, true);
    }

    /**
     * @param list<string> $axisDimensionKeys registry keys for active row/column axes
     *
     * @return list<string>
     */
    public static function allowedExcludingAxes(array $axisDimensionKeys): array
    {
        return array_values(array_filter(
            self::ALLOWED_DIMENSION_KEYS,
            static fn (string $key): bool => !\in_array($key, $axisDimensionKeys, true),
        ));
    }
}
