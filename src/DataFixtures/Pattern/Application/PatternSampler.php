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
    ): SampledAllocationAttributes {
        $createdAt = $this->sampleCreatedAt($pattern, $periodStart, $periodEnd);
        $transportMinutes = $this->percentileSampler->sampleMinutes($pattern->transportTimePercentiles());
        $arrivalAt = $createdAt->add(new \DateInterval('PT'.$transportMinutes.'M'));

        $gender = AllocationGender::from($this->categoricalSampler->sample($pattern->categoricalDistribution('gender')));
        $urgency = AllocationUrgency::from((int) $this->categoricalSampler->sample($pattern->categoricalDistribution('urgency')));

        $transportDistribution = $pattern->categoricalDistribution('transport_type');
        $transportType = [] !== $transportDistribution
            ? AllocationTransportType::from($this->categoricalSampler->sample($transportDistribution))
            : null;

        $departmentName = $this->categoricalSampler->sample($pattern->categoricalDistribution('department'));
        $specialityName = $this->categoricalSampler->sample($pattern->categoricalDistribution('speciality'));
        $assignmentName = $this->categoricalSampler->sample($pattern->categoricalDistribution('assignment'));
        $occasionName = $this->categoricalSampler->sample($pattern->categoricalDistribution('occasion'));

        $indicationNormalized = $this->sampleNullableLookup(
            $pattern,
            'indication_normalized',
            IndicationNormalized::class,
        );
        $infection = $this->sampleNullableLookup($pattern, 'infection', Infection::class);
        $secondaryTransport = $this->sampleNullableLookup($pattern, 'secondary_transport', SecondaryTransport::class);

        $indicationRaw = $indicationNormalized instanceof IndicationNormalized
            ? $this->lookupResolver->referenceIndicationRawForNormalized((string) $indicationNormalized->getName())
            : $this->lookupResolver->referenceAny(IndicationRaw::class);

        $flags = $pattern->flagProbabilities();

        return new SampledAllocationAttributes(
            createdAt: $createdAt,
            arrivalAt: $arrivalAt,
            gender: $gender,
            age: $this->sampleAge($pattern),
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
            requiresResus: $this->sampleFlag($flags, 'requires_resus', 0.05),
            requiresCathlab: $this->sampleFlag($flags, 'requires_cathlab', 0.01),
            isCpr: $this->sampleFlag($flags, 'is_cpr', 0.03),
            isVentilated: $this->sampleFlag($flags, 'is_ventilated', 0.08),
            isShock: $this->sampleFlag($flags, 'is_shock', 0.04),
            isPregnant: $this->sampleFlag($flags, 'is_pregnant', 0.02),
            isWorkAccident: $this->sampleFlag($flags, 'is_work_accident', 0.08),
            isWithPhysician: $this->sampleFlag($flags, 'is_with_physician', 0.13),
            departmentWasClosed: $this->sampleFlag($flags, 'department_was_closed', 0.05),
        );
    }

    private function sampleCreatedAt(
        DistributionPattern $pattern,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): \DateTimeImmutable {
        $hourDistribution = $pattern->categoricalDistribution('hour_of_day');
        $hour = [] !== $hourDistribution
            ? (int) $this->categoricalSampler->sample($hourDistribution)
            : random_int(0, 23);

        $startTs = $periodStart->getTimestamp();
        $endTs = max($startTs, $periodEnd->getTimestamp());
        $dayTs = random_int($startTs, $endTs);
        $day = new \DateTimeImmutable()->setTimestamp($dayTs)->setTime(0, 0);

        return $day->setTime($hour, random_int(0, 59));
    }

    private function sampleAge(DistributionPattern $pattern): int
    {
        $bucket = $this->categoricalSampler->sample($pattern->categoricalDistribution('age_bucket'));

        return match ($bucket) {
            '0-17' => random_int(1, 17),
            '18-39' => random_int(18, 39),
            '40-64' => random_int(40, 64),
            '65-99' => random_int(65, 99),
            default => random_int(1, 99),
        };
    }

    /**
     * @param array<string, float> $flags
     */
    private function sampleFlag(array $flags, string $name, float $default): bool
    {
        return $this->categoricalSampler->sampleBernoulli($flags[$name] ?? $default);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function sampleNullableLookup(DistributionPattern $pattern, string $key, string $class): ?object
    {
        $presence = $pattern->presenceProbability($key);
        if (null === $presence || !$this->categoricalSampler->sampleBernoulli($presence)) {
            return null;
        }

        $distribution = $pattern->categoricalDistribution($key);
        if ([] === $distribution) {
            return null;
        }

        $name = $this->categoricalSampler->sample($distribution);

        return $this->lookupResolver->reference($class, $name);
    }
}
