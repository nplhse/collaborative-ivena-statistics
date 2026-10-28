<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use App\DataFixtures\Structure\Application\DefaultStructurePatterns;
use App\DataFixtures\Structure\Application\DepartmentStructure;
use App\DataFixtures\Structure\Application\StructureRules;
use App\DataFixtures\Structure\Dto\Capability;
use App\DataFixtures\Structure\Dto\HospitalConfiguration;
use App\DataFixtures\Structure\Dto\MixTilt;
use App\DataFixtures\Structure\Dto\MixVariation;
use App\DataFixtures\Structure\Dto\StructurePatternSet;
use Random\Randomizer;

/**
 * Derives the profile of a hospital from its care tier, size and location and the structural patterns.
 *
 * A hospital draws one configuration (a coherent bundle of resources such as cath lab, stroke unit,
 * trauma level and obstetrics) by tier and size, then the number of further departments per field.
 * Both come from the hospital's own stream, so the same seed always gives the same hospital the same
 * structure. After the departments the hospital draws its volume factor (beds and a hospital factor)
 * and the tilt of its case mix, so hospitals of one tier and size differ; fields added later
 * (urology, ophthalmology, ENT) come after the tilt. New draws go last in the stream, so earlier
 * ones keep their values. A hospital never gets codes whose department it lacks (department gates).
 *
 * A ProfileSelection replaces tier, size, beds, the drawn configuration, single resources, the
 * volume (an explicit annual volume) or the closure share for targeted scenarios and #877.
 */
final readonly class HospitalProfileResolver
{
    public function __construct(
        private RandomStreams $streams,
        private WeightedCategoricalSampler $sampler,
        private DefaultStructurePatterns $structures,
    ) {
    }

    /**
     * @throws \DomainException when the selection names an unknown configuration or makes the structure incoherent
     */
    public function resolve(Hospital $hospital, int $seed, ?ProfileSelection $selection = null): HospitalProfile
    {
        $violations = $selection?->violations() ?? [];
        if ([] !== $violations) {
            throw new \DomainException(sprintf('The selected profile is not usable: %s.', implode('; ', $violations)));
        }

        $set = $this->structures->get();
        $tierEnum = $selection->tier ?? $hospital->getTier() ?? HospitalTier::BASIC;
        $tier = $tierEnum->value;
        $size = ($selection->size ?? $hospital->getSize() ?? HospitalSize::MEDIUM)->value;
        $beds = $selection->beds ?? $hospital->getBeds();
        $random = $this->streams->stream($seed, $hospital->getPublicIdString(), '-', RandomStreams::PROFILE);

        // Drawn even when a selection replaces it, so the departments below use the same numbers.
        $drawn = $this->sampler->sample($set->weightsFor($tier, $size), $random);
        $configuration = $this->select($set, $set->configuration($selection->configuration ?? $drawn), $selection);
        $resources = $configuration->resources;

        $offered = array_values(array_unique([...DepartmentStructure::CORE, ...DepartmentStructure::impliedBy($resources), ...$configuration->departments]));
        $offered = $this->drawFields(DepartmentStructure::FIELDS, $configuration, $offered, $random);

        // Drawn even when an explicit annual volume replaces it, so the tilt keeps its numbers.
        $volumeFactor = $set->volume->bedFactor($size, $beds) * $this->logNormal($set->volume->hospitalSpread, $random);
        $tilt = $this->tilt($set->mixVariation, $random);
        $offered = $this->drawFields(DepartmentStructure::LATE_FIELDS, $configuration, $offered, $random);

        return new HospitalProfile(
            segment: $this->segment($tierEnum, $hospital->getLocation()),
            resources: $resources,
            resusShare: $set->resusShares[$resources->traumaLevel->value] ?? 0.0,
            missingDepartments: DepartmentStructure::labels(array_values(array_diff(DepartmentStructure::all(), $offered))),
            configuration: $configuration->key,
            blockedIndications: [...$set->blockedIndications($resources), ...$set->blockedByDepartments($offered)],
            size: $size,
            volumeFactor: $volumeFactor,
            annualVolume: $selection?->annualVolume,
            closureShare: $selection->closureShare ?? $set->closureShares[$size] ?? 0.0,
            tilt: $tilt,
        );
    }

    /**
     * Draws the number of further departments per field and adds the first ones of the field.
     *
     * @param array<string, list<string>> $fields  speciality key → department keys, most common first
     * @param list<string>                $offered department keys
     *
     * @return list<string> department keys
     */
    private function drawFields(array $fields, HospitalConfiguration $configuration, array $offered, Randomizer $random): array
    {
        foreach ($fields as $field => $departments) {
            $count = (int) $this->sampler->sample($configuration->fieldCounts[$field] ?? [0 => 1.0], $random);
            $required = DepartmentStructure::FIELD_REQUIRES[$field] ?? null;
            if ($required instanceof Capability && !$configuration->resources->provides($required)) {
                continue;
            }
            $further = array_values(array_diff($departments, $offered));
            array_push($offered, ...\array_slice($further, 0, $count));
        }

        return $offered;
    }

    /**
     * One factor per indication category (first PZC digit 1 to 8), then old age, then urgency 1.
     */
    private function tilt(MixVariation $variation, Randomizer $random): MixTilt
    {
        $categories = [];
        for ($digit = 1; $digit <= 8; ++$digit) {
            $categories[$digit] = $this->logNormal($variation->categorySpread, $random);
        }

        return new MixTilt(
            categories: $categories,
            oldAge: $this->logNormal($variation->oldAgeSpread, $random),
            urgent: $this->logNormal($variation->urgentSpread, $random),
        );
    }

    /**
     * A log-normal factor with mean 1 (Box-Muller); 1 when the spread is 0. Always takes two numbers
     * from the stream, so later draws do not depend on the spread.
     */
    private function logNormal(float $spread, Randomizer $random): float
    {
        $u1 = max(1e-12, $random->nextFloat());
        $u2 = $random->nextFloat();
        if ($spread <= 0.0) {
            return 1.0;
        }
        $gauss = sqrt(-2.0 * log($u1)) * cos(2.0 * \M_PI * $u2);

        return exp($spread * $gauss - $spread * $spread / 2.0);
    }

    private function select(StructurePatternSet $set, HospitalConfiguration $configuration, ?ProfileSelection $selection): HospitalConfiguration
    {
        if (!$selection instanceof ProfileSelection) {
            return $configuration;
        }

        $resources = $configuration->resources->with(
            cathlab: $selection->cathlab,
            neurosurgery: $selection->neurosurgery,
            strokeUnit: $selection->strokeUnit,
            traumaLevel: $selection->traumaLevel,
            obstetrics: $selection->obstetrics,
            paediatrics: $selection->paediatrics,
        );
        $violations = StructureRules::resourceViolations($resources);
        if ([] !== $violations) {
            throw new \DomainException(sprintf('The selected structure is not coherent: %s. Supported configurations: %s.', implode('; ', $violations), implode(', ', array_keys($set->configurations))));
        }

        return $configuration->withResources($resources);
    }

    private function segment(HospitalTier $tier, ?HospitalLocation $location): string
    {
        return match (true) {
            HospitalTier::FULL === $tier => 'urban-full',
            HospitalLocation::RURAL === $location => 'rural-basic',
            default => 'urban-basic',
        };
    }
}
