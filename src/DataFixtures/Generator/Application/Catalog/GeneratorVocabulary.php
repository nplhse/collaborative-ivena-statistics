<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

use App\DataFixtures\Generator\Application\ClosurePlanner;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\DataFixtures\Generator\Application\Scenario\ScenarioCatalog;
use App\DataFixtures\Pattern\Application\PatternSampler;
use App\DataFixtures\Pattern\Application\PatternSet;

/**
 * Every department and speciality key, lookup value and PZC code the generator can write, collected
 * from the patterns, indication groups, hospital profiles, closures and scenarios (ADR 022). The
 * patterns and indication groups are those of the pattern set of the run: code defaults or a package.
 *
 * Departments and specialities are given as keys (`GeneratorKeys`); the sources still name them by
 * their default label, which is translated here.
 *
 * The generator speaks this vocabulary; `GeneratorCatalog` resolves it against the installed catalog,
 * so a check before generation finds every missing or ambiguous name, not only the ones that happen
 * to be drawn.
 */
final readonly class GeneratorVocabulary
{
    public function __construct(
        private PatternSet $patterns,
    ) {
    }

    /**
     * @return list<string> department keys, sorted
     *
     * @throws \InvalidArgumentException when a source names a department without a key
     */
    public function departments(): array
    {
        $names = array_merge(
            HospitalProfileResolver::CORE_DEPARTMENTS,
            array_keys(HospitalProfileResolver::OPTIONAL_DEPARTMENTS),
            array_keys(ClosurePlanner::DEPARTMENTS),
            array_keys(ClosurePlanner::SPECIALISED_DEPARTMENTS),
            array_values(PatternSampler::FALLBACK_DEPARTMENTS),
            [PatternSampler::FALLBACK_DEPARTMENT],
        );
        foreach ($this->patterns->indicationGroups()->all() as $group) {
            $names = array_merge($names, array_keys($group['departments']));
        }
        foreach (ScenarioCatalog::all() as $scenario) {
            $names = array_merge($names, $scenario->closedDepartments);
        }

        return $this->sorted(array_map(GeneratorKeys::department(...), $names));
    }

    /**
     * The speciality each department is written with: the field of the unit (IVENA "Fachgebiet").
     *
     * @return array<string, string> department key → speciality key
     */
    public function specialityOfDepartment(): array
    {
        $fields = [];
        foreach ($this->departments() as $department) {
            $fields[$department] = GeneratorKeys::DEPARTMENTS[$department]['speciality'];
        }

        return $fields;
    }

    /**
     * @return list<string> speciality keys, sorted
     */
    public function specialities(): array
    {
        return $this->sorted(array_values($this->specialityOfDepartment()));
    }

    /**
     * @return list<string> names of one lookup type, sorted
     *
     * @throws \InvalidArgumentException for departments and specialities, which come from departments()
     */
    public function lookups(CatalogLookupType $type): array
    {
        // The pattern key of a lookup type is its value.
        $key = match ($type) {
            CatalogLookupType::Assignment, CatalogLookupType::Occasion, CatalogLookupType::Infection, CatalogLookupType::SecondaryTransport => $type->value,
            default => throw new \InvalidArgumentException(sprintf('%s is not a lookup of the patterns.', $type->value)),
        };
        $names = [];
        foreach ($this->patterns->segmentNames() as $pattern) {
            foreach (array_keys($this->patterns->get($pattern)->categoricalDistribution($key)) as $name) {
                $names[] = (string) $name;
            }
        }

        return $this->sorted($names);
    }

    /**
     * @return list<string> three-digit PZC codes, sorted
     */
    public function indicationCodes(): array
    {
        $codes = [];
        foreach ($this->patterns->segmentNames() as $pattern) {
            foreach (array_keys($this->patterns->get($pattern)->categoricalDistribution('indication_normalized')) as $code) {
                $codes[] = str_pad((string) $code, 3, '0', \STR_PAD_LEFT);
            }
        }
        foreach (ScenarioCatalog::all() as $scenario) {
            foreach (array_keys($scenario->indications) as $code) {
                $codes[] = str_pad((string) $code, 3, '0', \STR_PAD_LEFT);
            }
        }

        return $this->sorted($codes);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names, \SORT_STRING);

        return $names;
    }
}
