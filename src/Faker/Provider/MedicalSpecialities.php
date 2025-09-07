<?php

namespace Faker\Provider;

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

    public function medicalDepartment(): string
    {
        return static::randomElement(static::$departments);
    }

    public function medicalSpecialty(): string
    {
        $allSpecialties = array_merge(...array_values(static::$specialties));

        return static::randomElement($allSpecialties);
    }

    /**
     * @return array{department: string, specialty: string}
     */
    public function medicalDepartmentWithSpecialty(): array
    {
        $department = static::randomElement(array_keys(static::$specialties));
        $specialty = static::randomElement(static::$specialties[$department]);

        return [
            'department' => $department,
            'specialty' => $specialty,
        ];
    }
}
