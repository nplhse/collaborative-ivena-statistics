<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

use App\DataFixtures\Pattern\Application\PatternSet;

/**
 * Checks every name and PZC code of the generator against the installed catalog before anything is
 * generated, so a missing or ambiguous reference stops a run before its first row, with the full list.
 *
 * The vocabulary depends on the pattern set of the run: a pattern package may use other PZC codes and
 * lookup values than the code defaults.
 */
final readonly class CatalogCompatibility
{
    public const string SPECIALITY_OF_DEPARTMENT = 'speciality of department';

    public const string INDICATION = 'indication';

    public function __construct(
        private GeneratorCatalog $catalog,
    ) {
    }

    /**
     * @param PatternSet|null $patterns the pattern set of the run; null for the code defaults
     *
     * @throws \InvalidArgumentException when the mapping file is invalid
     */
    public function check(?PatternSet $patterns = null): CatalogReport
    {
        $vocabulary = new GeneratorVocabulary($patterns ?? PatternSet::defaults());
        $mapping = $this->catalog->mapping();
        $sections = [];
        $mappingErrors = [];

        foreach (CatalogLookupType::cases() as $type) {
            $names = match ($type) {
                CatalogLookupType::Department => $vocabulary->departments(),
                CatalogLookupType::Speciality => $vocabulary->specialities(),
                default => $vocabulary->lookups($type),
            };
            $sections[$type->value] = array_map(fn (string $name): CatalogMatch => $this->catalog->match($type, $name), $names);
            foreach (array_diff(array_keys($mapping->names[$type->value] ?? []), $names) as $unknown) {
                $mappingErrors[] = sprintf('names.%s."%s" is not a %s the generator uses', $type->value, $unknown, $type->value);
            }
        }

        $departments = $vocabulary->departments();
        $sections[self::SPECIALITY_OF_DEPARTMENT] = array_map($this->catalog->matchSpecialityOf(...), $departments);
        foreach (array_diff(array_keys($mapping->specialityOfDepartment), $departments) as $unknown) {
            $mappingErrors[] = sprintf('speciality_of_department."%s" is not a department the generator uses', $unknown);
        }

        $codes = $vocabulary->indicationCodes();
        $sections[self::INDICATION] = array_map($this->catalog->matchIndication(...), $codes);
        foreach (array_diff(array_map(strval(...), array_keys($mapping->indications)), $codes) as $unknown) {
            $mappingErrors[] = sprintf('indications."%s" is not a PZC code the generator uses', $unknown);
        }

        return new CatalogReport($sections, $mappingErrors, $mapping->source);
    }

    /**
     * Call it before the first row of a run is written.
     *
     * @param PatternSet|null $patterns the pattern set of the run; null for the code defaults
     *
     * @throws CatalogMismatchException  with every missing or ambiguous name
     * @throws \InvalidArgumentException when the mapping file is invalid
     */
    public function assertCompatible(?PatternSet $patterns = null): void
    {
        $report = $this->check($patterns);
        if ($report->isCompatible()) {
            return;
        }

        $errors = $report->errors();

        throw new CatalogMismatchException(sprintf("The installed reference catalog cannot serve the generator (%d problems; app:generate:check-catalog shows them all):\n- %s", \count($errors), implode("\n- ", $errors)));
    }
}
