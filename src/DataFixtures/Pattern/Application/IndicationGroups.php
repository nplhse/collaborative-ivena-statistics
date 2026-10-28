<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

/**
 * What follows from the indication of a case: department, urgency, age, sex and flags (ADR 022).
 *
 * Groups are keyed by PZC code prefixes; the longest matching prefix wins, so `332` (STEMI) refines
 * `33` (chest pain), which refines `3` (internal medicine). The shares are coarse general knowledge
 * in five percent steps, not an export of real data. The speciality follows from the department.
 *
 * Flags are absolute probabilities, except `resus_factor`: it multiplies the resuscitation room share
 * of the hospital profile. A cath lab case also needs a hospital with a cath lab.
 *
 * @phpstan-type Group array{
 *     departments: array<string, float>,
 *     urgency: array<int, float>,
 *     ages: array<string, float>,
 *     female: float,
 *     resus_factor: float,
 *     flags: array<string, float>
 * }
 */
final class IndicationGroups
{
    private const array ADULTS = ['18-39' => 0.15, '40-64' => 0.30, '65-99' => 0.55];

    private const array PREGNANCY = [
        'departments' => ['Geburtshilfe' => 0.85, 'Pränatalmedizin' => 0.10, 'Gynäkologie' => 0.05],
        'urgency' => ['1' => 0.10, '2' => 0.55, '3' => 0.35],
        'ages' => ['15-17' => 0.02, '18-29' => 0.38, '30-39' => 0.55, '40-49' => 0.05],
        'female' => 1.0,
        'resus_factor' => 0.2,
        'flags' => ['is_pregnant' => 1.0],
    ];

    private const array FETAL_DANGER = [
        'departments' => ['Geburtshilfe' => 0.70, 'Pränatalmedizin' => 0.30],
        'urgency' => ['1' => 0.90, '2' => 0.10],
        'ages' => ['15-17' => 0.02, '18-29' => 0.38, '30-39' => 0.55, '40-49' => 0.05],
        'female' => 1.0,
        'resus_factor' => 2.0,
        'flags' => ['is_pregnant' => 1.0, 'is_with_physician' => 0.50],
    ];

    private const array GROUPS = [
        // Reanimation / ECMO
        '1' => [
            'departments' => ['Kardiologie' => 0.60, 'Neurologische Intensiv' => 0.10, 'Unfallchirurgie' => 0.15, 'Pulmologie' => 0.15],
            'urgency' => ['1' => 1.0],
            'ages' => ['18-39' => 0.10, '40-64' => 0.35, '65-99' => 0.55],
            'female' => 0.35,
            'resus_factor' => 10.0,
            'flags' => ['is_cpr' => 0.90, 'is_ventilated' => 0.80, 'is_shock' => 0.30, 'is_with_physician' => 0.90, 'requires_cathlab' => 0.30],
        ],
        // Injuries and accidents
        '2' => [
            'departments' => ['Unfallchirurgie' => 0.75, 'Allgemeinchirurgie' => 0.15, 'Neurochirurgie' => 0.05, 'Wirbelsäulenchirurgie' => 0.05],
            'urgency' => ['1' => 0.10, '2' => 0.45, '3' => 0.45],
            'ages' => ['0-17' => 0.10, '18-39' => 0.25, '40-64' => 0.25, '65-99' => 0.40],
            'female' => 0.45,
            'resus_factor' => 1.5,
            'flags' => ['is_work_accident' => 0.10, 'is_with_physician' => 0.10],
        ],
        '21' => [
            'departments' => ['Unfallchirurgie' => 0.80, 'Neurochirurgie' => 0.20],
            'urgency' => ['1' => 0.80, '2' => 0.20],
            'ages' => ['0-17' => 0.05, '18-39' => 0.45, '40-64' => 0.30, '65-99' => 0.20],
            'female' => 0.30,
            'resus_factor' => 15.0,
            'flags' => ['is_ventilated' => 0.30, 'is_shock' => 0.20, 'is_with_physician' => 0.80, 'is_work_accident' => 0.10],
        ],
        '22' => [
            'departments' => ['Unfallchirurgie' => 0.60, 'Neurochirurgie' => 0.30, 'Allgemeine Neurologie' => 0.10],
            'urgency' => ['1' => 0.15, '2' => 0.50, '3' => 0.35],
            'ages' => ['0-17' => 0.15, '18-39' => 0.20, '40-64' => 0.20, '65-99' => 0.45],
            'female' => 0.45,
            'resus_factor' => 2.0,
            'flags' => ['is_with_physician' => 0.15],
        ],
        '273' => [
            'departments' => ['Unfallchirurgie' => 0.90, 'Endoprothetik' => 0.10],
            'urgency' => ['2' => 0.70, '3' => 0.30],
            'ages' => ['40-64' => 0.10, '65-99' => 0.90],
            'female' => 0.70,
            'resus_factor' => 0.5,
            'flags' => [],
        ],
        // Internal medicine
        '3' => [
            'departments' => ['Gastroenterologie' => 0.30, 'Kardiologie' => 0.30, 'Pulmologie' => 0.20, 'Nephrologie' => 0.10, 'Infektiologie' => 0.10],
            'urgency' => ['1' => 0.05, '2' => 0.50, '3' => 0.45],
            'ages' => self::ADULTS,
            'female' => 0.50,
            'resus_factor' => 1.0,
            'flags' => [],
        ],
        '31' => [
            'departments' => ['Pulmologie' => 0.70, 'Infektiologie' => 0.15, 'Kardiologie' => 0.15],
            'urgency' => ['1' => 0.10, '2' => 0.55, '3' => 0.35],
            'ages' => self::ADULTS,
            'female' => 0.50,
            'resus_factor' => 1.0,
            'flags' => ['is_ventilated' => 0.03],
        ],
        '32' => [
            'departments' => ['Kardiologie' => 0.50, 'Gastroenterologie' => 0.30, 'Allgemeine Neurologie' => 0.20],
            'urgency' => ['1' => 0.05, '2' => 0.50, '3' => 0.45],
            'ages' => self::ADULTS,
            'female' => 0.55,
            'resus_factor' => 1.0,
            'flags' => [],
        ],
        '33' => [
            'departments' => ['Kardiologie' => 0.95, 'Pulmologie' => 0.05],
            'urgency' => ['1' => 0.30, '2' => 0.60, '3' => 0.10],
            'ages' => ['18-39' => 0.10, '40-64' => 0.40, '65-99' => 0.50],
            'female' => 0.40,
            'resus_factor' => 1.5,
            'flags' => ['requires_cathlab' => 0.15, 'is_with_physician' => 0.30],
        ],
        '332' => [
            'departments' => ['Kardiologie' => 1.0],
            'urgency' => ['1' => 0.90, '2' => 0.10],
            'ages' => ['18-39' => 0.05, '40-64' => 0.45, '65-99' => 0.50],
            'female' => 0.30,
            'resus_factor' => 3.0,
            'flags' => ['requires_cathlab' => 0.90, 'is_with_physician' => 0.70],
        ],
        '34' => [
            'departments' => ['Kardiologie' => 0.80, 'Nephrologie' => 0.10, 'Allgemeine Neurologie' => 0.10],
            'urgency' => ['1' => 0.15, '2' => 0.60, '3' => 0.25],
            'ages' => ['18-39' => 0.05, '40-64' => 0.30, '65-99' => 0.65],
            'female' => 0.50,
            'resus_factor' => 1.5,
            'flags' => ['requires_cathlab' => 0.05],
        ],
        '346' => [
            'departments' => ['Kardiologie' => 1.0],
            'urgency' => ['1' => 1.0],
            'ages' => ['40-64' => 0.35, '65-99' => 0.65],
            'female' => 0.35,
            'resus_factor' => 8.0,
            'flags' => ['is_shock' => 0.90, 'requires_cathlab' => 0.50, 'is_ventilated' => 0.30, 'is_with_physician' => 0.80],
        ],
        '35' => [
            'departments' => ['Gastroenterologie' => 0.50, 'Allgemeinchirurgie' => 0.30, 'Viszeralchirurgie' => 0.20],
            'urgency' => ['1' => 0.05, '2' => 0.45, '3' => 0.50],
            'ages' => ['0-17' => 0.05, '18-39' => 0.25, '40-64' => 0.30, '65-99' => 0.40],
            'female' => 0.55,
            'resus_factor' => 0.5,
            'flags' => [],
        ],
        '36' => [
            'departments' => ['Gastroenterologie' => 0.50, 'Suchtmedizin' => 0.50],
            'urgency' => ['1' => 0.05, '2' => 0.40, '3' => 0.55],
            'ages' => ['18-39' => 0.40, '40-64' => 0.45, '65-99' => 0.15],
            'female' => 0.30,
            'resus_factor' => 0.5,
            'flags' => [],
        ],
        // Neurology and psychiatry
        '41' => [
            'departments' => ['Allgemeine Neurologie' => 0.70, 'Epileptologie' => 0.20, 'Neurologische Intensiv' => 0.10],
            'urgency' => ['1' => 0.15, '2' => 0.55, '3' => 0.30],
            'ages' => ['0-17' => 0.05, '18-39' => 0.25, '40-64' => 0.30, '65-99' => 0.40],
            'female' => 0.45,
            'resus_factor' => 1.5,
            'flags' => ['is_with_physician' => 0.20],
        ],
        '42' => [
            'departments' => ['Schlaganfallmedizin' => 0.85, 'Allgemeine Neurologie' => 0.10, 'Neurochirurgie' => 0.05],
            'urgency' => ['1' => 0.50, '2' => 0.50],
            'ages' => ['18-39' => 0.05, '40-64' => 0.30, '65-99' => 0.65],
            'female' => 0.50,
            'resus_factor' => 2.0,
            'flags' => ['is_with_physician' => 0.40],
        ],
        '43' => [
            'departments' => ['Allgemeine Psychiatrie' => 0.70, 'Suchtmedizin' => 0.20, 'Gerontopsychiatrie' => 0.10],
            'urgency' => ['2' => 0.30, '3' => 0.70],
            'ages' => ['18-39' => 0.50, '40-64' => 0.35, '65-99' => 0.15],
            'female' => 0.45,
            'resus_factor' => 0.2,
            'flags' => [],
        ],
        // Paediatrics, obstetrics and gynaecology
        '5' => [
            'departments' => ['Allgemeine Pädiatrie' => 0.85, 'Neonatologie' => 0.05, 'Kinderkardiologie' => 0.05, 'Kindergastroenterologie' => 0.05],
            'urgency' => ['1' => 0.10, '2' => 0.50, '3' => 0.40],
            'ages' => ['0-17' => 1.0],
            'female' => 0.45,
            'resus_factor' => 0.5,
            'flags' => [],
        ],
        // Pregnancy by week of gestation; 52x, 53x and 54x share their wording
        '52' => self::PREGNANCY,
        '53' => self::PREGNANCY,
        '54' => self::PREGNANCY,
        // Acute fetal danger
        '530' => self::FETAL_DANGER,
        '540' => self::FETAL_DANGER,
        '55' => [
            'departments' => ['Gynäkologie' => 0.80, 'Geburtshilfe' => 0.20],
            'urgency' => ['1' => 0.05, '2' => 0.50, '3' => 0.45],
            'ages' => ['15-49' => 0.70, '50-90' => 0.30],
            'female' => 1.0,
            'resus_factor' => 0.5,
            'flags' => ['is_pregnant' => 0.30],
        ],
        // General medicine
        '8' => [
            'departments' => ['Allgemeinchirurgie' => 0.25, 'Gastroenterologie' => 0.25, 'Kardiologie' => 0.25, 'Allgemeine Neurologie' => 0.25],
            'urgency' => ['1' => 0.05, '2' => 0.40, '3' => 0.55],
            'ages' => ['0-17' => 0.05, '18-39' => 0.20, '40-64' => 0.30, '65-99' => 0.45],
            'female' => 0.50,
            'resus_factor' => 0.5,
            'flags' => [],
        ],
    ];

    /** Codes without a group of their own behave like internal medicine. */
    private const string FALLBACK = '3';

    /**
     * @return Group
     */
    public static function forCode(string $code): array
    {
        for ($length = \strlen($code); $length > 0; --$length) {
            // PHP keys numeric prefixes as int; codes starting with 0 (no patient) have no group.
            $prefix = substr($code, 0, $length);
            if ('0' !== $prefix[0] && ctype_digit($prefix) && isset(self::GROUPS[(int) $prefix])) {
                return self::GROUPS[(int) $prefix];
            }
        }

        return self::GROUPS[self::FALLBACK];
    }

    /**
     * @return array<int|string, Group>
     */
    public static function all(): array
    {
        return self::GROUPS;
    }
}
