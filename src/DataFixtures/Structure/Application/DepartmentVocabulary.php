<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Application;

use App\DataFixtures\Structure\Dto\Capability;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;

/**
 * The departments the generator knows, by field, and which of them follow from resources.
 *
 * Every hospital offers the core. A resource implies its departments (a stroke unit implies stroke
 * medicine and general neurology). The other departments of a field are listed most common first:
 * a hospital with two further departments of a field gets the first two.
 *
 * The names are the generator's vocabulary of the synthetic catalog (ADR 020); the catalog binding
 * of #875 resolves them against the installed catalog.
 */
final class DepartmentVocabulary
{
    /** Departments every hospital offers; they also take the cases of a missing department. */
    public const array CORE = ['Kardiologie', 'Gastroenterologie', 'Pulmologie', 'Allgemeinchirurgie', 'Unfallchirurgie'];

    /** Further departments by field, most common first. The order of the fields is part of the random stream. */
    public const array FIELDS = [
        'Innere Medizin' => ['Nephrologie', 'Infektiologie'],
        'Chirurgie' => ['Viszeralchirurgie'],
        'Orthopädie' => ['Endoprothetik', 'Wirbelsäulenchirurgie'],
        'Neurologie' => ['Allgemeine Neurologie', 'Neurologische Intensiv', 'Epileptologie'],
        'Psychiatrie' => ['Allgemeine Psychiatrie', 'Suchtmedizin', 'Gerontopsychiatrie'],
        'Pädiatrie' => ['Kinderkardiologie', 'Kindergastroenterologie'],
        'Gynäkologie und Geburtshilfe' => ['Gynäkologie'],
    ];

    /** A field whose further departments need a resource. */
    public const array FIELD_REQUIRES = ['Pädiatrie' => Capability::PAEDIATRICS];

    /** Departments that only a resource brings. */
    public const array IMPLIED = ['Neurochirurgie', 'Schlaganfallmedizin', 'Geburtshilfe', 'Pränatalmedizin', 'Neonatologie', 'Allgemeine Pädiatrie'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([...self::CORE, ...array_merge(...array_values(self::FIELDS)), ...self::IMPLIED]));
    }

    /**
     * @return list<string>
     */
    public static function impliedBy(HospitalResources $resources): array
    {
        $departments = [];
        if ($resources->neurosurgery) {
            $departments[] = 'Neurochirurgie';
        }
        if (StrokeUnitLevel::NONE !== $resources->strokeUnit) {
            $departments[] = 'Schlaganfallmedizin';
            $departments[] = 'Allgemeine Neurologie';
        }
        if ($resources->obstetrics->rank() >= ObstetricsLevel::BASIC->rank()) {
            $departments[] = 'Geburtshilfe';
        }
        if (ObstetricsLevel::PERINATAL === $resources->obstetrics) {
            $departments[] = 'Pränatalmedizin';
            $departments[] = 'Neonatologie';
        }
        if ($resources->paediatrics) {
            $departments[] = 'Allgemeine Pädiatrie';
        }

        return $departments;
    }
}
