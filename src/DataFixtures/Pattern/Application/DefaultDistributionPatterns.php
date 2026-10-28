<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\DataFixtures\Pattern\Dto\DistributionPattern;

/**
 * Plain, plausible distributions for synthetic allocations, keyed by the synthetic catalog (ADR 020).
 *
 * The shares are coarse (whole percent or five percent steps, common values only): general
 * knowledge, nudged towards all-hospital aggregates of #874 (#909), never derived from real data. A
 * segment sets the mix of indications, occasions, transport times and base flags; department,
 * urgency, age and sex follow from the indication (IndicationGroups), and PatternCouplings link
 * occasion, infection, hour and transport time to the case.
 */
final class DefaultDistributionPatterns
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['urban-full', 'urban-basic', 'rural-basic'];
    }

    public function get(string $name): DistributionPattern
    {
        return match ($name) {
            'urban-full' => $this->pattern(severe: true, rural: false),
            'urban-basic' => $this->pattern(severe: false, rural: false),
            'rural-basic' => $this->pattern(severe: false, rural: true),
            default => throw new \InvalidArgumentException(sprintf('Unknown distribution pattern "%s". Known: %s.', $name, implode(', ', self::names()))),
        };
    }

    private function pattern(bool $severe, bool $rural): DistributionPattern
    {
        return new DistributionPattern(
            distributions: [
                // Air rescue brings its patients to full-care hospitals.
                'transport_type' => $severe ? ['G' => 0.99, 'A' => 0.01] : ['G' => 1.0],
                'hour_of_day' => $this->hoursOfDay(),
                'assignment' => ['RD' => 0.65, 'ZLST' => 0.15, 'Patient' => 0.08, 'Arzt/Arzt' => 0.05, 'Einweisung' => 0.05, 'Notzuweisung' => 0.02],
                // Accident occasions are shares of injuries only (PatternCouplings): other cases draw
                // the rest of the table, so three quarters of them come from home.
                'occasion' => [
                    'Häuslicher Einsatz' => 0.60,
                    'Öffentlicher Raum' => 0.10,
                    'aus Arztpraxis' => 0.05,
                    'Sonstiger Einsatz' => 0.03,
                    'Sekundärverlegung' => 0.02,
                    'Verkehrsunfall' => 0.15,
                    'Arbeitsunfall' => 0.05,
                ],
                // "Sonstiges" is the common value; respiratory codes draw influenza and COVID more often (PatternCouplings).
                'infection' => ['_present' => 0.05, 'Sonstiges' => 0.60, 'MRSA' => 0.15, '3MRGN' => 0.05, 'Influenza' => 0.05, 'Noro' => 0.05, 'V.a. COVID' => 0.05, '4MRGN/CRE' => 0.03, 'TBC' => 0.02],
                'secondary_transport' => ['_present' => 0.02, 'Sekundärverlegung' => 0.40, 'Diagnostik' => 0.20, 'Intensivstation' => 0.20, 'Intervention' => 0.10, 'OP' => 0.10],
                'indication_normalized' => ['_present' => 1.0] + $this->indications($severe),
                // Base flags for indications whose group sets none; groups set the specific ones
                // (CPR, pregnancy, work accident, cath lab). The cath lab share only says whether
                // the hospital has one, the resuscitation room share comes from the hospital profile.
                'flags' => [
                    'requires_resus' => $severe ? 0.10 : 0.03,
                    'requires_cathlab' => $severe ? 0.03 : 0.01,
                    'is_cpr' => 0.0,
                    'is_ventilated' => 0.01,
                    'is_shock' => 0.0,
                    'is_pregnant' => 0.0,
                    'is_work_accident' => 0.0,
                    'is_with_physician' => 0.05,
                ],
                // Location is a hospital attribute, not the patients' distance: rural times are
                // hardly longer. Minutes are rounded and depend on urgency (PatternCouplings).
                'transport_time_minutes' => $rural
                    ? ['p25' => 15.0, 'p50' => 20.0, 'p75' => 25.0, 'p90' => 40.0]
                    : ['p25' => 15.0, 'p50' => 20.0, 'p75' => 25.0, 'p90' => 35.0],
            ],
        );
    }

    /**
     * Common PZC codes, weights roughly in percent of cases: injuries about 30, internal medicine
     * about 45, neurology and psychiatry about 13, children, gynaecology and obstetrics a few. Severe codes (STEMI,
     * cardiogenic shock, polytrauma, ROSC) are in every segment at low rates; the capability gates of
     * the hospital profile keep them away from hospitals without cath lab or trauma centre. Full care
     * gets more strokes, preterm births and acute fetal danger. Urology about 6, ENT about 4 and
     * ophthalmology a half: the department gates leave them out of hospitals without the department,
     * so category 7 is about a tenth of the cases where all three departments exist (#910).
     * Dermatology, oral surgery, geriatrics and "other" (701, 749, 751, 779) are left out.
     *
     * Keyed by code, not by wording: obstetric codes share their wording across 52x, 53x and 54x.
     *
     * @return array<string, float> PZC code → share
     */
    private function indications(bool $severe): array
    {
        $weights = [
            // Reanimation, ROSC
            '132' => 0.5,
            // Injuries
            '272' => 8.0, '224' => 4.0, '273' => 4.0, '223' => 3.0, '214' => 3.0, '222' => 2.0, '219' => 2.0,
            '254' => 2.0, '252' => 1.0, '275' => 1.0, '211' => 1.0,
            // Internal medicine
            '353' => 5.0, '322' => 4.0, '315' => 4.0, '311' => 3.0, '329' => 3.0, '331' => 3.0, '345' => 3.0,
            '312' => 2.0, '323' => 2.0, '333' => 2.0, '341' => 2.0, '343' => 2.0, '347' => 2.0, '349' => 2.0,
            '351' => 2.0, '359' => 2.0, '361' => 2.0, '332' => 1.0, '346' => 0.5,
            // Neurology and psychiatry
            '421' => 4.0, '412' => 2.0, '414' => 2.0, '419' => 2.0, '432' => 2.0, '415' => 1.0,
            // Children, gynaecology, pregnancy at term
            '511' => 1.0, '551' => 1.0, '526' => 1.0,
            // Urology, ENT, ophthalmology: only where the hospital has the department
            '719' => 1.5, '711' => 1.0, '713' => 1.0, '714' => 1.0, '715' => 0.5, '716' => 0.5, '717' => 0.5, '718' => 0.5,
            '731' => 2.0, '739' => 2.0,
            '729' => 0.5,
        ];
        if ($severe) {
            $weights['421'] = 5.0;
            // Full care also takes preterm births and acute fetal danger.
            $weights += ['525' => 0.5, '530' => 0.5];
        }

        $total = array_sum($weights);
        $byCode = [];
        foreach ($weights as $code => $weight) {
            $byCode[str_pad((string) $code, 3, '0', \STR_PAD_LEFT)] = round($weight / $total, 4);
        }

        return $byCode;
    }

    /**
     * About 2 percent of cases per night hour, a peak in the late morning and a slow decline into the
     * evening. Weekend nights get more (PatternCouplings).
     *
     * @return array<int, float> hour → share
     */
    private function hoursOfDay(): array
    {
        $weights = [3.0, 2.5, 2.0, 2.0, 2.0, 2.0, 2.5, 3.5, 5.0, 6.5, 7.0, 7.0, 6.5, 6.0, 6.0, 5.5, 5.5, 5.0, 5.0, 4.5, 4.0, 4.0, 3.5, 3.0];
        $total = array_sum($weights);
        $hours = [];
        foreach ($weights as $hour => $weight) {
            $hours[$hour] = round($weight / $total, 4);
        }

        return $hours;
    }
}
