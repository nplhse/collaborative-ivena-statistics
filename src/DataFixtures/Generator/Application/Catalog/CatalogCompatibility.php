<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

/**
 * Checks every name and PZC code of the generator against the installed catalog before anything is
 * generated, so a missing or ambiguous reference stops a run before its first row, with the full list.
 */
final readonly class CatalogCompatibility
{
    public const string SPECIALITY_OF_DEPARTMENT = 'speciality of department';

    public const string INDICATION = 'indication';

    public function __construct(
        private GeneratorVocabulary $vocabulary,
        private GeneratorCatalog $catalog,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the mapping file is invalid
     */
    public function check(): CatalogReport
    {
        $mapping = $this->catalog->mapping();
        $sections = [];
        $mappingErrors = [];

        foreach (CatalogLookupType::cases() as $type) {
            $names = match ($type) {
                CatalogLookupType::Department => $this->vocabulary->departments(),
                CatalogLookupType::Speciality => $this->vocabulary->specialities(),
                default => $this->vocabulary->lookups($type),
            };
            $sections[$type->value] = array_map(fn (string $name): CatalogMatch => $this->catalog->match($type, $name), $names);
            foreach (array_diff(array_keys($mapping->names[$type->value] ?? []), $names) as $unknown) {
                $mappingErrors[] = sprintf('names.%s."%s" is not a %s the generator uses', $type->value, $unknown, $type->value);
            }
        }

        $departments = $this->vocabulary->departments();
        $sections[self::SPECIALITY_OF_DEPARTMENT] = array_map($this->catalog->matchSpecialityOf(...), $departments);
        foreach (array_diff(array_keys($mapping->specialityOfDepartment), $departments) as $unknown) {
            $mappingErrors[] = sprintf('speciality_of_department."%s" is not a department the generator uses', $unknown);
        }

        $codes = $this->vocabulary->indicationCodes();
        $sections[self::INDICATION] = array_map($this->catalog->matchIndication(...), $codes);
        foreach (array_diff(array_map(strval(...), array_keys($mapping->indications)), $codes) as $unknown) {
            $mappingErrors[] = sprintf('indications."%s" is not a PZC code the generator uses', $unknown);
        }

        return new CatalogReport($sections, $mappingErrors, $mapping->source);
    }

    /**
     * Call it before the first row of a run is written.
     *
     * @throws CatalogMismatchException  with every missing or ambiguous name
     * @throws \InvalidArgumentException when the mapping file is invalid
     */
    public function assertCompatible(): void
    {
        $report = $this->check();
        if ($report->isCompatible()) {
            return;
        }

        $errors = $report->errors();

        throw new CatalogMismatchException(sprintf("The installed reference catalog cannot serve the generator (%d problems; app:generate:check-catalog shows them all):\n- %s", \count($errors), implode("\n- ", $errors)));
    }
}
