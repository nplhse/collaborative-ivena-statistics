<?php

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * Added this interface to fix an issue with PHPStan that could not recognize
 * the hospital() method from the Hospital faker provider. To make things work
 * we assign self::faker() just for this one case to a $faker variable and
 * manually assign this interface alongside the usual generator.
 */
interface MedicalSpecialityFakerMethods
{
    /**
     * Returns a random medical department name.
     *
     * @return non-empty-string
     */
    public function medicalDepartment(): string;

    /**
     * Returns a random medical speciality name.
     *
     * @return non-empty-string
     */
    public function medicalSpeciality(): string;

    /**
     * Returns a random medical department name.
     *
     * @return array<string>
     */
    public function medicalDepartmentWithSpecialty(): array;
}
