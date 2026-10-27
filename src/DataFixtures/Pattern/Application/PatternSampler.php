<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;
use App\DataFixtures\Pattern\Infrastructure\Sampling\PercentileSampler;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use Random\Randomizer;

final readonly class PatternSampler
{
    public function __construct(
        private WeightedCategoricalSampler $categoricalSampler,
        private PercentileSampler $percentileSampler,
        private PatternLookupResolver $lookupResolver,
    ) {
    }

    public function sample(
        DistributionPattern $pattern,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        Randomizer $random,
    ): SampledAllocationAttributes {
        $createdAt = $this->sampleCreatedAt($pattern, $periodStart, $periodEnd, $random);
        $transportMinutes = $this->percentileSampler->sampleMinutes($pattern->transportTimePercentiles(), $random);
        $arrivalAt = $createdAt->add(new \DateInterval('PT'.$transportMinutes.'M'));

        $gender = AllocationGender::from($this->categoricalSampler->sample($pattern->categoricalDistribution('gender'), $random));
        $urgency = AllocationUrgency::from((int) $this->categoricalSampler->sample($pattern->categoricalDistribution('urgency'), $random));

        $transportDistribution = $pattern->categoricalDistribution('transport_type');
        $transportType = [] !== $transportDistribution
            ? AllocationTransportType::from($this->categoricalSampler->sample($transportDistribution, $random))
            : null;

        $departmentName = $this->categoricalSampler->sample($pattern->categoricalDistribution('department'), $random);
        $specialityName = $this->categoricalSampler->sample($pattern->categoricalDistribution('speciality'), $random);
        $assignmentName = $this->categoricalSampler->sample($pattern->categoricalDistribution('assignment'), $random);
        $occasionName = $this->categoricalSampler->sample($pattern->categoricalDistribution('occasion'), $random);

        $indicationNormalized = $this->sampleNullableLookup(
            $pattern,
            'indication_normalized',
            IndicationNormalized::class,
            $random,
        );
        $infection = $this->sampleNullableLookup($pattern, 'infection', Infection::class, $random);
        $secondaryTransport = $this->sampleNullableLookup($pattern, 'secondary_transport', SecondaryTransport::class, $random);

        $indicationRaw = $indicationNormalized instanceof IndicationNormalized
            ? $this->lookupResolver->referenceIndicationRawForNormalized((string) $indicationNormalized->getName())
            : $this->lookupResolver->referenceAny(IndicationRaw::class);

        $flags = $pattern->flagProbabilities();

        return new SampledAllocationAttributes(
            createdAt: $createdAt,
            arrivalAt: $arrivalAt,
            gender: $gender,
            age: $this->sampleAge($pattern, $random),
            urgency: $urgency,
            transportType: $transportType,
            speciality: $this->lookupResolver->reference(Speciality::class, $specialityName),
            department: $this->lookupResolver->reference(Department::class, $departmentName),
            assignment: $this->lookupResolver->reference(Assignment::class, $assignmentName),
            occasion: $this->lookupResolver->reference(Occasion::class, $occasionName),
            indicationRaw: $indicationRaw,
            indicationNormalized: $indicationNormalized,
            infection: $infection,
            secondaryTransport: $secondaryTransport,
            requiresResus: $this->sampleFlag($flags, 'requires_resus', 0.05, $random),
            requiresCathlab: $this->sampleFlag($flags, 'requires_cathlab', 0.01, $random),
            isCpr: $this->sampleFlag($flags, 'is_cpr', 0.03, $random),
            isVentilated: $this->sampleFlag($flags, 'is_ventilated', 0.08, $random),
            isShock: $this->sampleFlag($flags, 'is_shock', 0.04, $random),
            isPregnant: $this->sampleFlag($flags, 'is_pregnant', 0.02, $random),
            isWorkAccident: $this->sampleFlag($flags, 'is_work_accident', 0.08, $random),
            isWithPhysician: $this->sampleFlag($flags, 'is_with_physician', 0.13, $random),
            departmentWasClosed: $this->sampleFlag($flags, 'department_was_closed', 0.05, $random),
        );
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

    private function sampleAge(DistributionPattern $pattern, Randomizer $random): int
    {
        $bucket = $this->categoricalSampler->sample($pattern->categoricalDistribution('age_bucket'), $random);

        return match ($bucket) {
            '0-17' => $random->getInt(1, 17),
            '18-39' => $random->getInt(18, 39),
            '40-64' => $random->getInt(40, 64),
            '65-99' => $random->getInt(65, 99),
            default => $random->getInt(1, 99),
        };
    }

    /**
     * @param array<string, float> $flags
     */
    private function sampleFlag(array $flags, string $name, float $default, Randomizer $random): bool
    {
        return $this->categoricalSampler->sampleBernoulli($flags[$name] ?? $default, $random);
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
