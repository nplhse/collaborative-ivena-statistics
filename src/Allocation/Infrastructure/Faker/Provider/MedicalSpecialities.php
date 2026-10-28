<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class MedicalSpecialities extends Base
{
    /** @var list<string> */
    protected static array $departments = [
        'Innere Medizin',
        'Chirurgie',
        'Allgemeinmedizin',
        'Pädiatrie',
        'Gynäkologie und Geburtshilfe',
        'Psychiatrie',
        'Neurologie',
        'Orthopädie',
        'Dermatologie',
        'Radiologie',
        'HNO (Hals-Nasen-Ohren)',
        'Urologie',
    ];

    /** @var array<string, list<string>> */
    protected static array $specialties = [
        'Innere Medizin' => [
            'Kardiologie',
            'Gastroenterologie',
            'Nephrologie',
            'Endokrinologie',
            'Rheumatologie',
            'Hämatologie',
            'Pulmologie',
            'Infektiologie',
        ],
        'Chirurgie' => [
            'Allgemeinchirurgie',
            'Unfallchirurgie',
            'Gefäßchirurgie',
            'Herzchirurgie',
            'Neurochirurgie',
            'Viszeralchirurgie',
            'Thoraxchirurgie',
            'Plastische Chirurgie',
        ],
        'Pädiatrie' => [
            'Neonatologie',
            'Kinderkardiologie',
            'Kindergastroenterologie',
            'Sozialpädiatrie',
            'Allgemeine Pädiatrie',
        ],
        'Gynäkologie und Geburtshilfe' => [
            'Pränatalmedizin',
            'Gynäkologische Onkologie',
            'Reproduktionsmedizin',
            'Gynäkologie',
            'Geburtshilfe',
        ],
        'Psychiatrie' => [
            'Kinder- und Jugendpsychiatrie',
            'Gerontopsychiatrie',
            'Suchtmedizin',
            'Forensische Psychiatrie',
            'Allgemeine Psychiatrie',
        ],
        'Neurologie' => [
            'Schlaganfallmedizin',
            'Epileptologie',
            'Multiple Sklerose',
            'Neuroimmunologie',
            'Neurologische Intensiv',
            'Allgemeine Neurologie',
        ],
        'Orthopädie' => [
            'Sportorthopädie',
            'Wirbelsäulenchirurgie',
            'Endoprothetik',
            'Handchirurgie',
        ],
        'Dermatologie' => [
            'Dermatoonkologie',
            'Allergologie',
            'Ästhetische Dermatologie',
        ],
        'Radiologie' => [
            'Neuroradiologie',
            'Kinderradiologie',
            'Interventionelle Radiologie',
        ],
        'HNO (Hals-Nasen-Ohren)' => [
            'Phoniatrie',
            'Audiologie',
            'Rhinologie',
            'Schlafmedizin',
        ],
        'Urologie' => [
            'Uroonkologie',
            'Andrologie',
            'Pädiatrische Urologie',
        ],
    ];

    /**
     * Every department and speciality name the provider knows.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_unique(array_merge(self::$departments, ...array_values(self::$specialties))));
    }

    /**
     * The broad fields (IVENA "Fachgebiet", a speciality in the catalog).
     *
     * @return list<string>
     */
    public static function fieldNames(): array
    {
        return self::$departments;
    }

    /**
     * The narrower units (IVENA "Fachbereich", a department in the catalog).
     *
     * @return list<string>
     */
    public static function unitNames(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::$specialties))));
    }

    public function medicalDepartment(): string
    {
        return self::randomElement(self::$departments);
    }

    public function medicalSpecialty(): string
    {
        $allSpecialties = array_merge(...array_values(self::$specialties));

        return self::randomElement($allSpecialties);
    }

    /**
     * @return array{department: string, specialty: string}
     */
    public function medicalDepartmentWithSpecialty(): array
    {
        $department = self::randomElement(array_keys(self::$specialties));
        $specialty = self::randomElement(self::$specialties[$department]);

        return [
            'department' => $department,
            'specialty' => $specialty,
        ];
    }

    /**
     * A generated alias of a department or speciality name: an abbreviation (as a catalog
     * may spell it) or a typo (as a faulty source may), never a curated alias.
     *
     * @return array{name: string, classification: 'unambiguous_alias'|'faulty_catalog'}
     */
    public function departmentAlias(string $name): array
    {
        $abbreviated = strtr($name, [
            'Allgemeine ' => 'Allg. ',
            'Chirurgie' => 'Chir.',
            ' und ' => ' u. ',
            'Medizin' => 'Med.',
            'Gynäkologie' => 'Gyn.',
            'Pädiatrie' => 'Päd.',
        ]);
        if ($abbreviated !== $name && 1 === self::numberBetween(0, 1)) {
            return ['name' => $abbreviated, 'classification' => 'unambiguous_alias'];
        }

        $length = mb_strlen($name);
        $at = self::numberBetween(1, max(1, $length - 3));
        $typo = mb_substr($name, 0, $at).mb_substr($name, $at + 1, 1).mb_substr($name, $at, 1).mb_substr($name, $at + 2);
        if ($typo === $name) {
            $typo = $name.'e';
        }

        return ['name' => $typo, 'classification' => 'faulty_catalog'];
    }
}
