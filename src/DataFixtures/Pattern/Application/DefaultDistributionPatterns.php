<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Faker\Provider\PzcCodes;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Pattern\Dto\PatternSegment;

/**
 * Plain, plausible distributions for synthetic allocations, keyed by the synthetic catalog (ADR 020).
 *
 * The shares are coarse general knowledge (whole or five percent steps, common values only),
 * not an export of real data. Real patterns, conditional chains and coherence rules belong to #792.
 */
final class DefaultDistributionPatterns
{
    public const string SOURCE = 'defaults';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['urban-full', 'urban-basic', 'rural-basic'];
    }

    /**
     * The pattern of the hospital's segment: full care, rural basic care or urban basic care.
     */
    public function forHospital(Hospital $hospital): DistributionPattern
    {
        return match (true) {
            HospitalTier::FULL === $hospital->getTier() => $this->get('urban-full'),
            HospitalLocation::RURAL === $hospital->getLocation() => $this->get('rural-basic'),
            default => $this->get('urban-basic'),
        };
    }

    public function get(string $name): DistributionPattern
    {
        return match ($name) {
            'urban-full' => $this->pattern($name, HospitalTier::FULL, HospitalLocation::URBAN, severe: true, rural: false),
            'urban-basic' => $this->pattern($name, HospitalTier::BASIC, HospitalLocation::URBAN, severe: false, rural: false),
            'rural-basic' => $this->pattern($name, HospitalTier::BASIC, HospitalLocation::RURAL, severe: false, rural: true),
            default => throw new \InvalidArgumentException(sprintf('Unknown distribution pattern "%s". Known: %s.', $name, implode(', ', self::names()))),
        };
    }

    private function pattern(string $name, HospitalTier $tier, HospitalLocation $location, bool $severe, bool $rural): DistributionPattern
    {
        return new DistributionPattern(
            segment: new PatternSegment($tier, $location),
            meta: ['source' => self::SOURCE, 'name' => $name],
            distributions: [
                'urgency' => $severe ? ['1' => 0.20, '2' => 0.55, '3' => 0.25] : ['1' => 0.10, '2' => 0.50, '3' => 0.40],
                'gender' => ['M' => 0.50, 'F' => 0.49, 'X' => 0.01],
                'transport_type' => $severe ? ['G' => 0.95, 'A' => 0.05] : ['G' => 0.99, 'A' => 0.01],
                'age_bucket' => ['0-17' => 0.05, '18-39' => 0.20, '40-64' => 0.30, '65-99' => 0.45],
                'hour_of_day' => $this->hoursOfDay(),
                'speciality' => [
                    'Innere Medizin' => 0.40,
                    'Chirurgie' => 0.25,
                    'Neurologie' => 0.10,
                    'Orthopädie' => 0.05,
                    'Pädiatrie' => 0.05,
                    'Gynäkologie und Geburtshilfe' => 0.05,
                    'Psychiatrie' => 0.05,
                    'Urologie' => 0.03,
                    'HNO (Hals-Nasen-Ohren)' => 0.02,
                ],
                'department' => $severe
                    ? [
                        'Kardiologie' => 0.20,
                        'Gastroenterologie' => 0.10,
                        'Pulmologie' => 0.10,
                        'Unfallchirurgie' => 0.15,
                        'Allgemeinchirurgie' => 0.10,
                        'Neurochirurgie' => 0.05,
                        'Schlaganfallmedizin' => 0.10,
                        'Allgemeine Neurologie' => 0.05,
                        'Allgemeine Pädiatrie' => 0.05,
                        'Geburtshilfe' => 0.05,
                        'Allgemeine Psychiatrie' => 0.05,
                    ]
                    : [
                        'Kardiologie' => 0.20,
                        'Gastroenterologie' => 0.15,
                        'Pulmologie' => 0.10,
                        'Unfallchirurgie' => 0.20,
                        'Allgemeinchirurgie' => 0.15,
                        'Allgemeine Neurologie' => 0.10,
                        'Geburtshilfe' => 0.05,
                        'Allgemeine Psychiatrie' => 0.05,
                    ],
                'assignment' => ['RD' => 0.75, 'Notzuweisung' => 0.10, 'ZLST' => 0.05, 'Einweisung' => 0.05, 'Arzt/Arzt' => 0.04, 'Patient' => 0.01],
                'occasion' => [
                    'Häuslicher Einsatz' => $severe ? 0.50 : 0.55,
                    'Öffentlicher Raum' => 0.15,
                    'aus Arztpraxis' => 0.10,
                    'Sonstiger Einsatz' => 0.05,
                    'Sekundärverlegung' => $severe ? 0.10 : 0.05,
                    'Verkehrsunfall' => 0.05,
                    'Arbeitsunfall' => 0.05,
                ],
                'infection' => ['_present' => 0.05, 'MRSA' => 0.40, '3MRGN' => 0.20, 'Influenza' => 0.15, 'Noro' => 0.10, 'V.a. COVID' => 0.10, '4MRGN/CRE' => 0.05],
                'secondary_transport' => ['_present' => $severe ? 0.10 : 0.05, 'Sekundärverlegung' => 0.40, 'Diagnostik' => 0.20, 'Intensivstation' => 0.20, 'Intervention' => 0.10, 'OP' => 0.10],
                'indication_normalized' => ['_present' => 1.0] + $this->indications($severe),
                'flags' => [
                    'requires_resus' => $severe ? 0.10 : 0.03,
                    'requires_cathlab' => $severe ? 0.03 : 0.01,
                    'is_cpr' => 0.01,
                    'is_ventilated' => $severe ? 0.05 : 0.02,
                    'is_shock' => 0.02,
                    'is_pregnant' => 0.02,
                    'is_work_accident' => 0.03,
                    'is_with_physician' => $severe ? 0.20 : 0.10,
                    'department_was_closed' => 0.03,
                ],
                'transport_time_minutes' => $rural
                    ? ['p25' => 15.0, 'p50' => 22.0, 'p75' => 32.0, 'p90' => 45.0]
                    : ['p25' => 10.0, 'p50' => 15.0, 'p75' => 22.0, 'p90' => 30.0],
            ],
        );
    }

    /**
     * Common PZC codes by their official wording; the full-care segment gets more severe cases.
     *
     * @return array<string, float>
     */
    private function indications(bool $severe): array
    {
        $weights = [
            '322' => 0.10, '331' => 0.08, '311' => 0.08, '353' => 0.08, '272' => 0.08,
            '329' => 0.08, '315' => 0.05, '224' => 0.05, '361' => 0.05, '809' => 0.05,
            '273' => 0.04, '341' => 0.04, '412' => 0.03, '414' => 0.03, '345' => 0.03,
            '432' => 0.03, '421' => 0.03, '551' => 0.02, '511' => 0.02,
        ];
        if ($severe) {
            $weights += ['332' => 0.03, '211' => 0.02, '132' => 0.01, '346' => 0.01];
            $weights['421'] = 0.05;
        }

        // Codes whose official wording is unique, so the sampler finds the indication by its name.
        $total = array_sum($weights);
        $byName = [];
        foreach ($weights as $code => $weight) {
            $byName[PzcCodes::name(str_pad((string) $code, 3, '0', \STR_PAD_LEFT))] = round($weight / $total, 4);
        }

        return $byName;
    }

    /**
     * More allocations by day than by night.
     *
     * @return array<int, float> hour → share
     */
    private function hoursOfDay(): array
    {
        $weights = [2.0, 1.5, 1.0, 1.0, 1.0, 1.0, 2.0, 3.0, 5.0, 6.0, 6.0, 6.0, 6.0, 6.0, 6.0, 5.5, 5.5, 5.0, 4.5, 4.0, 3.5, 3.0, 2.5, 2.0];
        $total = array_sum($weights);
        $hours = [];
        foreach ($weights as $hour => $weight) {
            $hours[$hour] = round($weight / $total, 4);
        }

        return $hours;
    }
}
