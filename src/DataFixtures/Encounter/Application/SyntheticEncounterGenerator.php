<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use Random\Randomizer;

/**
 * Generates the ED visits of one hospital and month for its synthetic allocations, with known truth
 * (#1052, ADR 022, ADR 029).
 *
 * Groups (exact counts from EncounterShares): linked visits of allocations, EMS arrivals without
 * allocation, self-presenters, visits with unknown arrival route and repeat visits under the case
 * number of an earlier visit. On top of that, some visits have no ED admission time (first assessment
 * contact only) and some get their first assessment contact documented only after the first export.
 *
 * Linked visits: ED admission mostly a few minutes after the IVENA arrival (−5 … +15 min), outliers
 * outside the pass-1 window of the matcher (−60 … −21 or +46 … +120 min); age exact or 1–5 years off;
 * sex as in IVENA; arrival mode `physician_vehicle` with an emergency physician or by air, otherwise
 * `ambulance` (a fifth of urgency 3 `patient_transport`); work accident documented as in IVENA; an
 * admission goes to the allocation's department.
 *
 * Deterministic: one random stream per seed, hospital and month (`encounters`); the order of the
 * given allocations does not matter. Never reads the clock.
 */
final readonly class SyntheticEncounterGenerator
{
    public const string STREAM = 'encounters';

    private const array HOUR_WEIGHTS = [2, 1, 1, 1, 1, 1, 2, 3, 5, 7, 8, 8, 7, 7, 7, 7, 7, 7, 6, 6, 5, 4, 3, 2];

    /** MTS diagram codes as the tool derives them from the German labels (MtsCatalog, mts-de.json). */
    private const array MTS_DIAGRAMS = [
        'thoraxschmerz' => 3,
        'atemproblem_bei_erwachsenen' => 3,
        'abdominelle_schmerzen_bei_erwachsenen' => 4,
        'stuerze' => 3,
        'extremitaetenprobleme' => 5,
        'kopfverletzung' => 2,
        'wunden' => 3,
        'kollaps' => 2,
        'unwohlsein_bei_erwachsenen' => 4,
        'rueckenschmerz' => 2,
    ];

    /** Latest physician contact after the first assessment contact, by MTS category (minutes). */
    private const array PHYSICIAN_TARGET = ['red' => 0, 'orange' => 10, 'yellow' => 30, 'green' => 90, 'blue' => 120];

    private const array ISOLATION = ['mrsa', 'covid19', 'gastroenteritis', 'mdro'];

    public function __construct(
        private RandomStreams $streams,
    ) {
    }

    /**
     * @param string                   $hospital    public id of the hospital (part of the stream key)
     * @param \DateTimeImmutable       $monthStart  first day of the month, 00:00
     * @param \DateTimeImmutable       $lastDay     last generated day; visits of other groups lie on days up to it
     * @param list<AllocationSnapshot> $allocations the allocations of the month
     */
    public function generate(int $seed, string $hospital, \DateTimeImmutable $monthStart, \DateTimeImmutable $lastDay, array $allocations, EncounterShares $shares = new EncounterShares()): GeneratedEncounters
    {
        $monthStart = $monthStart->modify('first day of this month')->setTime(0, 0);
        $monthEnd = $monthStart->modify('last day of this month');
        $lastDay = min($monthEnd, $lastDay->setTime(0, 0));
        if ($lastDay < $monthStart) {
            throw new \InvalidArgumentException(sprintf('The month %s starts after the last day %s.', $monthStart->format('Y-m'), $lastDay->format('Y-m-d')));
        }

        $random = $this->streams->stream($seed, $hospital, $monthStart->format('Y-m'), self::STREAM);

        usort($allocations, static fn (AllocationSnapshot $a, AllocationSnapshot $b): int => [$a->arrivalAt, $a->id] <=> [$b->arrivalAt, $b->id]);

        $linkedCount = EncounterShares::count(\count($allocations), $shares->linked);
        $linkedPositions = $this->subset($random, \count($allocations), $linkedCount);
        $linked = [];
        $withoutVisit = [];
        foreach ($allocations as $position => $allocation) {
            if (isset($linkedPositions[$position])) {
                $linked[] = $allocation;
            } else {
                $withoutVisit[] = $allocation->id;
            }
        }
        sort($withoutVisit);

        $outliers = $this->subset($random, $linkedCount, EncounterShares::count($linkedCount, $shares->timeOutliers));
        $ageNoise = $this->subset($random, $linkedCount, EncounterShares::count($linkedCount, $shares->ageNoise));
        $departments = array_values(array_unique(array_filter(array_map(static fn (AllocationSnapshot $a): ?string => $a->departmentName, $linked))));
        sort($departments);

        /** @var list<array{kind: SyntheticVisitKind, admission: \DateTimeImmutable, age: int, sex: string, allocation: ?AllocationSnapshot, outlier: bool, ageDifference: int}> $drafts */
        $drafts = [];
        foreach ($linked as $index => $allocation) {
            $outlier = isset($outliers[$index]);
            $age = max(1, min(99, $allocation->age));
            $difference = isset($ageNoise[$index]) ? $this->ageDifference($random, $age) : 0;
            $drafts[] = [
                'kind' => SyntheticVisitKind::Linked,
                'admission' => $allocation->arrivalAt->setTime((int) $allocation->arrivalAt->format('H'), (int) $allocation->arrivalAt->format('i'))->modify(sprintf('%+d minutes', $this->admissionOffset($random, $outlier))),
                'age' => $age + $difference,
                'sex' => $allocation->gender,
                'allocation' => $allocation,
                'outlier' => $outlier,
                'ageDifference' => $difference,
            ];
        }

        $groups = [
            [SyntheticVisitKind::EmsWithoutAllocation, EncounterShares::count($linkedCount, $shares->emsWithoutAllocation)],
            [SyntheticVisitKind::SelfPresenter, EncounterShares::count($linkedCount, $shares->selfPresenters)],
            [SyntheticVisitKind::UnknownRoute, EncounterShares::count($linkedCount, $shares->unknownRoute)],
        ];
        $days = (int) $monthStart->diff($lastDay)->days + 1;
        foreach ($groups as [$kind, $count]) {
            for ($i = 0; $i < $count; ++$i) {
                $drafts[] = [
                    'kind' => $kind,
                    'admission' => $this->visitTime($random, $monthStart, $days),
                    'age' => $this->age($random),
                    'sex' => $this->pick($random, ['M' => 49, 'F' => 50, 'X' => 1]),
                    'allocation' => null,
                    'outlier' => false,
                    'ageDifference' => 0,
                ];
            }
        }

        // Case numbers follow the order of admission, like a hospital information system would issue them.
        usort($drafts, static fn (array $a, array $b): int => [$a['admission'], $a['kind']->value, $a['allocation']?->id] <=> [$b['admission'], $b['kind']->value, $b['allocation']?->id]);
        $caseNumbers = [];
        foreach (array_keys($drafts) as $index) {
            $caseNumbers[$index] = sprintf('9%s%05d', $monthStart->format('ym'), $index + 1);
        }

        $baseCount = \count($drafts);
        foreach (array_keys($this->subset($random, $baseCount, EncounterShares::count($baseCount, $shares->repeatVisits))) as $original) {
            $drafts[] = [
                'kind' => SyntheticVisitKind::RepeatVisit,
                'admission' => $drafts[$original]['admission']->modify(sprintf('+%d minutes', $random->getInt(3 * 60, 36 * 60))),
                'age' => $drafts[$original]['age'],
                'sex' => $drafts[$original]['sex'],
                'allocation' => null,
                'outlier' => false,
                'ageDifference' => 0,
            ];
            $caseNumbers[] = $caseNumbers[$original];
        }

        $total = \count($drafts);
        $missingCount = EncounterShares::count($total, $shares->missingAdmission);
        $lateCount = EncounterShares::count($total, $shares->lateAssessment);
        $order = $random->shuffleArray(range(0, $total - 1));
        $missingAdmission = array_flip(\array_slice($order, 0, $missingCount));
        $late = array_flip(\array_slice($order, $missingCount, $lateCount));

        $visits = [];
        foreach ($drafts as $index => $draft) {
            $cells = $this->cells($random, $draft, $departments);
            if (isset($missingAdmission[$index])) {
                $cells['ed_admission_at'] = '';
            }

            $visits[] = new SyntheticVisit(
                caseNumber: $caseNumbers[$index],
                kind: $draft['kind'],
                cells: $cells,
                allocationId: $draft['allocation']?->id,
                timeOutlier: $draft['outlier'],
                ageDifference: $draft['ageDifference'],
                lateAssessment: isset($late[$index]),
            );
        }

        usort($visits, static fn (SyntheticVisit $a, SyntheticVisit $b): int => [$a->matchingAnchor(), $a->caseNumber] <=> [$b->matchingAnchor(), $b->caseNumber]);

        return new GeneratedEncounters($visits, $withoutVisit);
    }

    /**
     * @param array{kind: SyntheticVisitKind, admission: \DateTimeImmutable, age: int, sex: string, allocation: ?AllocationSnapshot, outlier: bool, ageDifference: int} $draft
     * @param list<string>                                                                                                                                              $departments department names of the month's linked allocations
     *
     * @return array<string, string>
     */
    private function cells(Randomizer $random, array $draft, array $departments): array
    {
        $kind = $draft['kind'];
        $allocation = $draft['allocation'];
        $admission = $draft['admission'];

        [$arrivalMode, $referral] = match ($kind) {
            SyntheticVisitKind::Linked => $this->linkedArrival($random, $allocation ?? throw new \LogicException('A linked visit has an allocation.')),
            SyntheticVisitKind::EmsWithoutAllocation => $this->emsArrival($this->pick($random, ['ambulance' => 70, 'patient_transport' => 20, 'physician_vehicle' => 10])),
            SyntheticVisitKind::SelfPresenter, SyntheticVisitKind::RepeatVisit => ['self', $this->pick($random, ['self' => 60, 'physician' => 25, 'kv_practice' => 10, 'other' => 5])],
            SyntheticVisitKind::UnknownRoute => [$this->pick($random, ['other' => 1, '' => 1]), ''],
        };

        $category = match ($kind) {
            SyntheticVisitKind::Linked => match ($allocation?->urgency) {
                1 => $this->pick($random, ['red' => 40, 'orange' => 60]),
                2 => $this->pick($random, ['orange' => 30, 'yellow' => 60, 'green' => 10]),
                default => $this->pick($random, ['yellow' => 40, 'green' => 55, 'blue' => 5]),
            },
            SyntheticVisitKind::EmsWithoutAllocation => $this->pick($random, ['orange' => 25, 'yellow' => 50, 'green' => 25]),
            default => $this->pick($random, ['orange' => 8, 'yellow' => 35, 'green' => 50, 'blue' => 7]),
        };

        $firstContact = $admission->modify(sprintf('+%d minutes', 'red' === $category ? $random->getInt(1, 3) : $random->getInt(1, 15)));
        $target = self::PHYSICIAN_TARGET[$category];
        $firstPhysician = $firstContact->modify(sprintf('+%d minutes', $random->getInt(0, (int) round((float) $target * 1.5) + 10)));
        $treatmentEnd = $firstPhysician->modify(sprintf('+%d minutes', $random->getInt(30, 240)));
        $edDischarge = $treatmentEnd->modify(sprintf('+%d minutes', $random->getInt(5, 60)));

        $admittedShare = match (true) {
            SyntheticVisitKind::Linked === $kind => [1 => 85, 2 => 60][$allocation->urgency ?? 3] ?? 35,
            SyntheticVisitKind::EmsWithoutAllocation === $kind => 45,
            SyntheticVisitKind::UnknownRoute === $kind => 25,
            default => 15,
        };
        $roll = $random->getInt(1, 100);
        $disposition = match (true) {
            $roll <= $admittedShare => 'admitted',
            $roll <= $admittedShare + 2 => 'transferred_external',
            default => $this->pick($random, ['home' => 80, 'to_physician' => 10, 'left_before_completion' => 5, 'against_advice' => 3, 'other' => 2]),
        };
        $careLevel = '';
        $department = '';
        if ('admitted' === $disposition || 'transferred_external' === $disposition) {
            $careLevel = 'red' === $category
                ? $this->pick($random, ['icu' => 35, 'monitoring' => 30, 'ward' => 30, 'or' => 5])
                : $this->pick($random, ['icu' => 5, 'monitoring' => 15, 'ward' => 75, 'unknown' => 5]);
        }
        if ('admitted' === $disposition) {
            $department = $allocation->departmentName ?? ([] === $departments ? '' : $departments[$random->getInt(0, \count($departments) - 1)]);
        }

        $cells = [
            'age' => (string) max(1, min(99, $draft['age'])),
            'sex' => $draft['sex'],
            'arrival_mode' => $arrivalMode,
            'referral_source' => $referral,
            'admission_type' => 'emergency',
            'case_status' => 'admitted' === $disposition ? 'inpatient' : 'outpatient',
            'ed_admission_at' => $admission->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'first_assessment_contact_at' => $firstContact->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'assessment_start_at' => $firstContact->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'assessment_end_at' => $firstContact->modify(sprintf('+%d minutes', $random->getInt(2, 6)))->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'physician_target_at' => $firstContact->modify(sprintf('+%d minutes', $target))->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'first_physician_at' => $firstPhysician->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'treatment_end_at' => $treatmentEnd->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'ed_discharge_at' => $edDischarge->format(SyntheticVisit::TIMESTAMP_FORMAT),
            'mts_category' => $category,
            'mts_diagram' => $this->pick($random, self::MTS_DIAGRAMS),
            'pain_score' => $random->getInt(1, 100) <= 60 ? (string) $random->getInt(0, 10) : '',
            'disposition' => $disposition,
            'care_level' => $careLevel,
            'admitting_department' => $department,
            'work_accident' => (true === $allocation?->isWorkAccident || (null === $allocation && $random->getInt(1, 100) <= 2)) ? '1' : '',
            'tetanus_protected' => $random->getInt(1, 100) <= 5 ? '1' : '',
            'isolation' => $random->getInt(1, 100) <= 2 ? self::ISOLATION[$random->getInt(0, \count(self::ISOLATION) - 1)] : '',
        ];

        return $cells + $this->vitals($random, $category);
    }

    /**
     * @return array<string, string>
     */
    private function vitals(Randomizer $random, string $category): array
    {
        if ($random->getInt(1, 100) > 85) {
            return [];
        }

        $severe = 'red' === $category || 'orange' === $category;

        return [
            'vitals_reference' => 'documented_initial',
            'bp_systolic' => (string) $random->getInt($severe ? 80 : 100, $severe ? 200 : 170),
            'bp_diastolic' => (string) $random->getInt(50, 100),
            'heart_rate' => (string) $random->getInt($severe ? 45 : 55, $severe ? 150 : 110),
            'temperature' => sprintf('%.1f', $random->getInt(360, $severe ? 400 : 385) / 10),
            'respiratory_rate' => (string) $random->getInt(12, $severe ? 32 : 22),
            'glucose' => $random->getInt(1, 100) <= 30 ? (string) $random->getInt(70, 250) : '',
            'spo2' => (string) $random->getInt($severe ? 85 : 93, 100),
            'gcs' => (string) ('red' === $category ? $random->getInt(8, 15) : 15),
        ];
    }

    /**
     * @return array{0: string, 1: string} arrival mode and referral source
     */
    private function linkedArrival(Randomizer $random, AllocationSnapshot $allocation): array
    {
        if ($allocation->isWithPhysician || $allocation->isAirTransport) {
            return ['physician_vehicle', 'emergency_physician'];
        }

        return [$allocation->urgency >= 3 && $random->getInt(1, 100) <= 20 ? 'patient_transport' : 'ambulance', 'ems'];
    }

    /**
     * @return array{0: string, 1: string} arrival mode and referral source
     */
    private function emsArrival(string $arrivalMode): array
    {
        return [$arrivalMode, 'physician_vehicle' === $arrivalMode ? 'emergency_physician' : 'ems'];
    }

    /**
     * Minutes from the IVENA arrival to the documented ED admission.
     */
    private function admissionOffset(Randomizer $random, bool $outlier): int
    {
        if ($outlier) {
            return 0 === $random->getInt(0, 1) ? -$random->getInt(21, 60) : $random->getInt(46, 120);
        }

        return match ($this->pick($random, ['early' => 10, 'soon' => 55, 'later' => 35])) {
            'early' => -$random->getInt(1, 5),
            'soon' => $random->getInt(0, 5),
            default => $random->getInt(6, 15),
        };
    }

    /**
     * 1–5 years up or down, staying within 1–99.
     */
    private function ageDifference(Randomizer $random, int $age): int
    {
        $difference = $random->getInt(1, 5);
        $sign = 0 === $random->getInt(0, 1) ? -1 : 1;
        if ($age + $sign * $difference < 1 || $age + $sign * $difference > 99) {
            $sign = -$sign;
        }

        return $sign * $difference;
    }

    private function age(Randomizer $random): int
    {
        return match ($this->pick($random, ['child' => 10, 'young' => 25, 'middle' => 30, 'old' => 20, 'oldest' => 15])) {
            'child' => $random->getInt(1, 17),
            'young' => $random->getInt(18, 39),
            'middle' => $random->getInt(40, 64),
            'old' => $random->getInt(65, 79),
            default => $random->getInt(80, 99),
        };
    }

    private function visitTime(Randomizer $random, \DateTimeImmutable $monthStart, int $days): \DateTimeImmutable
    {
        $roll = $random->getInt(1, array_sum(self::HOUR_WEIGHTS));
        foreach (self::HOUR_WEIGHTS as $hour => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                break;
            }
        }

        return $monthStart->modify(sprintf('+%d days', $random->getInt(0, $days - 1)))->setTime($hour, $random->getInt(0, 59));
    }

    /**
     * Exactly $count of the positions 0 … $size − 1, drawn without replacement.
     *
     * @return array<int, true>
     */
    private function subset(Randomizer $random, int $size, int $count): array
    {
        if (0 === $size || 0 === $count) {
            return [];
        }

        return array_fill_keys(\array_slice($random->shuffleArray(range(0, $size - 1)), 0, $count), true);
    }

    /**
     * @param array<string, int> $weights
     */
    private function pick(Randomizer $random, array $weights): string
    {
        $roll = $random->getInt(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $value;
            }
        }

        throw new \LogicException('Weights must be positive.');
    }
}
