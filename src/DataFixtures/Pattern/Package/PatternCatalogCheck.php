<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * Checks that every PZC code and lookup name of a pattern package exists in the selected catalog.
 */
final class PatternCatalogCheck
{
    /**
     * @return list<PatternPackageViolation>
     */
    public static function check(PatternPackage $package, PatternCatalogNames $catalog): array
    {
        $violations = [];
        foreach ($package->segments as $name => $segment) {
            $path = 'segments.'.$name;
            $violations = [
                ...$violations,
                ...self::missing($path.'.indications.shares', $segment->indications->shares, $catalog->indicationCodes, 'PZC code'),
                ...self::missing($path.'.occasions', $segment->occasions, $catalog->occasions, 'Occasion'),
                ...self::missing($path.'.assignments', $segment->assignments, $catalog->assignments, 'Assignment'),
                ...self::missing($path.'.infections.shares', $segment->infections->shares, $catalog->infections, 'Infection'),
                ...self::missing($path.'.secondary_transports.shares', $segment->secondaryTransports->shares, $catalog->secondaryTransports, 'Secondary transport'),
            ];
        }
        foreach ($package->indicationGroups as $prefix => $group) {
            $violations = [...$violations, ...self::missing('indication_groups.'.$prefix.'.departments', $group->departments, $catalog->departments, 'Department')];
        }

        return $violations;
    }

    /**
     * @param array<array-key, float> $table
     * @param list<string>            $known
     *
     * @return list<PatternPackageViolation>
     */
    private static function missing(string $path, array $table, array $known, string $label): array
    {
        $known = array_flip($known);
        $violations = [];
        foreach (array_keys($table) as $key) {
            if (!isset($known[(string) $key])) {
                $violations[] = new PatternPackageViolation($path.'.'.$key, sprintf('%s "%s" is not in the selected reference catalog.', $label, $key), (string) $key);
            }
        }

        return $violations;
    }
}
