<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Application;

use App\DataFixtures\Structure\Dto\Capability;
use App\DataFixtures\Structure\Dto\HospitalConfiguration;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;
use App\DataFixtures\Structure\Dto\StructurePatternSet;
use App\DataFixtures\Structure\Dto\TraumaLevel;

/**
 * Hand-written hospital configurations (ADR 022): manually curated, not sampled from data. Fields
 * are speciality keys and departments department keys of the generator vocabulary (#875).
 *
 * Weights are coarse (five percent steps) and follow general knowledge, rounded towards the
 * aggregates of #874 where at least five hospitals stand behind a value: most extended-care hospitals
 * have a cath lab, some basic-care hospitals do; most full-care hospitals have obstetrics, not all.
 * Small hospitals with a specialised resource (cath lab, neurosurgery, stroke unit) are uncommon but
 * supported. The order of the configurations is part of the random stream, so new ones go last.
 */
final class DefaultStructurePatterns
{
    private const array BASIC_FIELDS = [
        'internal-medicine' => [0 => 0.30, 1 => 0.50, 2 => 0.20],
        'surgery' => [0 => 0.50, 1 => 0.50],
        'orthopaedics' => [0 => 0.40, 1 => 0.50, 2 => 0.10],
        'neurology' => [0 => 0.60, 1 => 0.40],
        'psychiatry' => [0 => 0.80, 1 => 0.20],
        'gynaecology-obstetrics' => [0 => 0.60, 1 => 0.40],
    ];

    private const array EXTENDED_FIELDS = [
        'internal-medicine' => [1 => 0.40, 2 => 0.60],
        'surgery' => [0 => 0.20, 1 => 0.80],
        'orthopaedics' => [1 => 0.50, 2 => 0.50],
        'neurology' => [0 => 0.30, 1 => 0.50, 2 => 0.20],
        'psychiatry' => [0 => 0.30, 1 => 0.40, 2 => 0.20, 3 => 0.10],
        'paediatrics' => [0 => 0.60, 1 => 0.30, 2 => 0.10],
        'gynaecology-obstetrics' => [1 => 1.0],
    ];

    private const array FULL_FIELDS = [
        'internal-medicine' => [2 => 1.0],
        'surgery' => [1 => 1.0],
        'orthopaedics' => [1 => 0.20, 2 => 0.80],
        'neurology' => [1 => 0.30, 2 => 0.70],
        'psychiatry' => [1 => 0.20, 2 => 0.30, 3 => 0.50],
        'paediatrics' => [1 => 0.30, 2 => 0.70],
        'gynaecology-obstetrics' => [1 => 1.0],
    ];

    /** PZC code prefix → what a hospital needs to receive the case. */
    private const array GATES = [
        '211' => Capability::TRAUMA_REGIONAL, '212' => Capability::TRAUMA_REGIONAL,
        '332' => Capability::CATHLAB, '346' => Capability::CATHLAB,
        // Preterm births (22+0 to 36+6 weeks, triplets) in each block of the PZC list.
        '523' => Capability::OBSTETRICS_PERINATAL, '524' => Capability::OBSTETRICS_PERINATAL, '525' => Capability::OBSTETRICS_PERINATAL, '528' => Capability::OBSTETRICS_PERINATAL,
        '533' => Capability::OBSTETRICS_PERINATAL, '534' => Capability::OBSTETRICS_PERINATAL, '535' => Capability::OBSTETRICS_PERINATAL, '538' => Capability::OBSTETRICS_PERINATAL,
        '543' => Capability::OBSTETRICS_PERINATAL, '544' => Capability::OBSTETRICS_PERINATAL, '545' => Capability::OBSTETRICS_PERINATAL, '548' => Capability::OBSTETRICS_PERINATAL,
    ];

    /** Share of allocations that need the resuscitation room, by trauma level. */
    private const array RESUS = ['none' => 0.01, 'basic' => 0.02, 'regional' => 0.05, 'supra' => 0.10];

    public function get(): StructurePatternSet
    {
        $basic = new HospitalResources(traumaLevel: TraumaLevel::BASIC);
        $extended = new HospitalResources(cathlab: true, strokeUnit: StrokeUnitLevel::REGIONAL, traumaLevel: TraumaLevel::REGIONAL, obstetrics: ObstetricsLevel::BASIC, paediatrics: true);
        $full = new HospitalResources(cathlab: true, neurosurgery: true, strokeUnit: StrokeUnitLevel::SUPRA, traumaLevel: TraumaLevel::SUPRA, obstetrics: ObstetricsLevel::PERINATAL, paediatrics: true);
        $withoutPaediatrics = self::EXTENDED_FIELDS;
        unset($withoutPaediatrics['paediatrics']);

        return new StructurePatternSet(
            configurations: [
                new HospitalConfiguration('basic-general', $this->weights(basic: [0.55, 0.40, 0.30]), $basic, fieldCounts: self::BASIC_FIELDS),
                new HospitalConfiguration('basic-obstetrics', $this->weights(basic: [0.20, 0.20, 0.20]), $basic->with(obstetrics: ObstetricsLevel::BASIC), fieldCounts: ['gynaecology-obstetrics' => [1 => 1.0]] + self::BASIC_FIELDS),
                new HospitalConfiguration('basic-cardio', $this->weights(basic: [0.10, 0.20, 0.25]), $basic->with(cathlab: true), fieldCounts: self::BASIC_FIELDS),
                new HospitalConfiguration('basic-stroke', $this->weights(basic: [0.10, 0.15, 0.20]), $basic->with(strokeUnit: StrokeUnitLevel::REGIONAL), fieldCounts: ['neurology' => [0 => 0.70, 1 => 0.30]] + self::BASIC_FIELDS),
                new HospitalConfiguration('basic-neurosurgery', $this->weights(basic: [0.05, 0.05, 0.05]), $basic->with(neurosurgery: true, traumaLevel: TraumaLevel::REGIONAL), fieldCounts: self::BASIC_FIELDS),
                new HospitalConfiguration('extended-general', $this->weights(extended: [0.40, 0.45, 0.40]), $extended, fieldCounts: self::EXTENDED_FIELDS),
                new HospitalConfiguration('extended-no-stroke', $this->weights(extended: [0.25, 0.20, 0.10]), $extended->with(strokeUnit: StrokeUnitLevel::NONE, obstetrics: ObstetricsLevel::NONE, paediatrics: false), fieldCounts: $withoutPaediatrics),
                new HospitalConfiguration('extended-perinatal', $this->weights(extended: [0.05, 0.15, 0.25]), $extended->with(obstetrics: ObstetricsLevel::PERINATAL), fieldCounts: self::EXTENDED_FIELDS),
                new HospitalConfiguration('extended-neurosurgery', $this->weights(extended: [0.05, 0.10, 0.20]), $extended->with(neurosurgery: true), fieldCounts: self::EXTENDED_FIELDS),
                new HospitalConfiguration('extended-no-cathlab', $this->weights(extended: [0.25, 0.10, 0.05]), $extended->with(cathlab: false), fieldCounts: self::EXTENDED_FIELDS),
                new HospitalConfiguration('full-maximum', $this->weights(full: [0.70, 0.70, 0.70]), $full, fieldCounts: self::FULL_FIELDS),
                new HospitalConfiguration('full-no-obstetrics', $this->weights(full: [0.30, 0.30, 0.30]), $full->with(obstetrics: ObstetricsLevel::NONE), fieldCounts: self::FULL_FIELDS),
            ],
            gates: self::GATES,
            resusShares: self::RESUS,
        );
    }

    /**
     * @param list<float> $basic    weights for Small, Medium, Large
     * @param list<float> $extended
     * @param list<float> $full
     *
     * @return array<string, array<string, float>>
     */
    private function weights(array $basic = [], array $extended = [], array $full = []): array
    {
        $weights = [];
        foreach (['Basic' => $basic, 'Extended' => $extended, 'Full' => $full] as $tier => $values) {
            if ([] !== $values) {
                $weights[$tier] = array_combine(StructureRules::SIZES, $values);
            }
        }

        return $weights;
    }
}
