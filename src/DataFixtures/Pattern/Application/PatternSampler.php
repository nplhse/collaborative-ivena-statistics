<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;
use App\DataFixtures\Pattern\Infrastructure\Sampling\PercentileSampler;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use Random\Randomizer;

final readonly class PatternSampler
{
    /** Draws of the indication before a case falls back to a core department. */
    private const int INDICATION_TRIES = 5;

    /** Department for a case whose indication the hospital cannot treat, by field of the indication's first department. */
    public const array FALLBACK_DEPARTMENTS = [
        'Chirurgie' => 'Allgemeinchirurgie',
        'Orthopädie' => 'Unfallchirurgie',
    ];

    public const string FALLBACK_DEPARTMENT = 'Gastroenterologie';

    public function __construct(
        private WeightedCategoricalSampler $categoricalSampler,
        private PercentileSampler $percentileSampler,
        private PatternLookupResolver $lookupResolver,
    ) {
    }

    /**
     * Draws the indication first; department, urgency, age, sex and flags follow from it
     * (IndicationGroups), then the coherence rules apply.
     *
     * A hospital gets only departments it offers: an indication whose departments are all missing
     * is drawn again (a hospital without obstetrics gets hardly any births); after a few tries the
     * case goes to a core department every hospital offers.
     */
    public function sample(
        DistributionPattern $pattern,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        Randomizer $random,
    ): SampledAllocationAttributes {
        $createdAt = $this->sampleCreatedAt($pattern, $periodStart, $periodEnd, $random);
        $transportMinutes = $this->percentileSampler->sampleMinutes($pattern->transportTimePercentiles(), $random);
        $arrivalAt = $createdAt->add(new \DateInterval('PT'.$transportMinutes.'M'));

        $groups = $pattern->indicationGroups();
        $missing = array_flip($pattern->departmentsNotOffered());
        $code = $this->sampleIndicationCode($pattern, $random);
        for ($try = 1; $try < self::INDICATION_TRIES && [] === array_diff_key($groups->forCode($code ?? '')['departments'], $missing); ++$try) {
            $code = $this->sampleIndicationCode($pattern, $random);
        }
        $infection = $this->sampleNullableLookup($pattern, 'infection', Infection::class, $random);
        $secondaryTransport = $this->sampleNullableLookup($pattern, 'secondary_transport', SecondaryTransport::class, $random);
        [$indicationNormalized, $indicationRaw] = null !== $code
            ? $this->lookupResolver->indicationForCode($code)
            : [null, $this->lookupResolver->referenceAny(IndicationRaw::class)];

        $group = $groups->forCode($code ?? '');

        $departmentName = $this->categoricalSampler->sample($this->offeredDepartments($group['departments'], $missing), $random);
        $female = $group['female'];
        $other = $female >= 1.0 ? 0.0 : 0.01;
        $gender = AllocationGender::from($this->categoricalSampler->sample(['F' => $female * (1.0 - $other), 'M' => (1.0 - $female) * (1.0 - $other), 'X' => $other], $random));

        $transportDistribution = $pattern->categoricalDistribution('transport_type');
        $transportType = [] !== $transportDistribution
            ? AllocationTransportType::from($this->categoricalSampler->sample($transportDistribution, $random))
            : null;
        $assignmentName = $this->categoricalSampler->sample($pattern->categoricalDistribution('assignment'), $random);
        $occasionName = $this->categoricalSampler->sample($pattern->categoricalDistribution('occasion'), $random);

        // The pattern carries the hospital profile: resus share, and a cath lab share of 0 without a cath lab.
        $base = $pattern->flagProbabilities();
        $groupFlags = $group['flags'];
        $hasCathlab = ($base['requires_cathlab'] ?? 0.0) > 0.0;

        $case = CoherenceRules::apply([
            'gender' => $gender,
            'age' => $this->sampleAge($group['ages'], $random),
            'urgency' => AllocationUrgency::from((int) $this->categoricalSampler->sample($group['urgency'], $random)),
            'field' => MedicalSpecialities::fieldOfUnit($departmentName),
            'pregnant' => $this->flag($groupFlags['is_pregnant'] ?? $base['is_pregnant'] ?? 0.0, $random),
            'cpr' => $this->flag($groupFlags['is_cpr'] ?? 0.0, $random),
            'resus' => $this->flag(min(1.0, ($base['requires_resus'] ?? 0.05) * $group['resus_factor']), $random),
            'shock' => $this->flag($groupFlags['is_shock'] ?? $base['is_shock'] ?? 0.0, $random),
        ], $random);

        return new SampledAllocationAttributes(
            createdAt: $createdAt,
            arrivalAt: $arrivalAt,
            gender: $case['gender'],
            age: $case['age'],
            urgency: $case['urgency'],
            transportType: $transportType,
            speciality: $this->lookupResolver->specialityFor($departmentName),
            department: $this->lookupResolver->reference(Department::class, $departmentName),
            assignment: $this->lookupResolver->reference(Assignment::class, $assignmentName),
            occasion: $this->lookupResolver->reference(Occasion::class, $occasionName),
            indicationRaw: $indicationRaw,
            indicationNormalized: $indicationNormalized,
            infection: $infection,
            secondaryTransport: $secondaryTransport,
            requiresResus: $case['resus'],
            requiresCathlab: $hasCathlab && $this->flag($groupFlags['requires_cathlab'] ?? 0.0, $random),
            isCpr: $case['cpr'],
            isVentilated: $this->flag($groupFlags['is_ventilated'] ?? $base['is_ventilated'] ?? 0.0, $random),
            isShock: $case['shock'],
            isPregnant: $case['pregnant'],
            isWorkAccident: $this->flag($groupFlags['is_work_accident'] ?? $base['is_work_accident'] ?? 0.0, $random),
            isWithPhysician: $this->flag($groupFlags['is_with_physician'] ?? $base['is_with_physician'] ?? 0.0, $random),
        );
    }

    /**
     * @param array<string, float> $departments
     * @param array<string, int>   $missing     departments the hospital does not offer, as keys
     *
     * @return array<string, float>
     */
    private function offeredDepartments(array $departments, array $missing): array
    {
        $offered = array_diff_key($departments, $missing);
        if ([] !== $offered) {
            return $offered;
        }

        $field = MedicalSpecialities::fieldOfUnit((string) array_key_first($departments));

        return [self::FALLBACK_DEPARTMENTS[$field] ?? self::FALLBACK_DEPARTMENT => 1.0];
    }

    private function sampleCreatedAt(
        DistributionPattern $pattern,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        Randomizer $random,
    ): \DateTimeImmutable {
        $hourDistribution = $pattern->categoricalDistribution('hour_of_day');
        $hour = [] !== $hourDistribution
            ? (int) $this->categoricalSampler->sample($hourDistribution, $random)
            : $random->getInt(0, 23);

        // A whole day between start and end, derived from the period (same time zone, no wall clock).
        $firstDay = $periodStart->setTime(0, 0);
        $days = max(0, (int) $firstDay->diff($periodEnd->setTime(0, 0))->days);
        $day = $firstDay->modify(sprintf('+%d days', $random->getInt(0, $days)));

        return $day->setTime($hour, $random->getInt(0, 59));
    }

    /**
     * @param array<string, float> $ages age ranges ("18-39") and their shares
     */
    private function sampleAge(array $ages, Randomizer $random): int
    {
        [$min, $max] = array_map(intval(...), explode('-', $this->categoricalSampler->sample($ages, $random)));

        return $random->getInt($min, $max);
    }

    private function flag(float $probability, Randomizer $random): bool
    {
        return $this->categoricalSampler->sampleBernoulli($probability, $random);
    }

    /**
     * The PZC code of the indication, or null for a case without one. Patterns key indications by
     * code, so codes with the same wording stay apart.
     */
    private function sampleIndicationCode(DistributionPattern $pattern, Randomizer $random): ?string
    {
        $presence = $pattern->presenceProbability('indication_normalized');
        if (null === $presence || !$this->categoricalSampler->sampleBernoulli($presence, $random)) {
            return null;
        }

        $distribution = $pattern->categoricalDistribution('indication_normalized');
        if ([] === $distribution) {
            return null;
        }

        return str_pad($this->categoricalSampler->sample($distribution, $random), 3, '0', \STR_PAD_LEFT);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function sampleNullableLookup(DistributionPattern $pattern, string $key, string $class, Randomizer $random): ?object
    {
        $presence = $pattern->presenceProbability($key);
        if (null === $presence || !$this->categoricalSampler->sampleBernoulli($presence, $random)) {
            return null;
        }

        $distribution = $pattern->categoricalDistribution($key);
        if ([] === $distribution) {
            return null;
        }

        $name = $this->categoricalSampler->sample($distribution, $random);

        return $this->lookupResolver->reference($class, $name);
    }
}
