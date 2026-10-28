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
use App\DataFixtures\Structure\Application\DepartmentVocabulary;
use App\DataFixtures\Structure\Application\StructureRules;
use App\DataFixtures\Structure\Dto\Capability;
use App\DataFixtures\Structure\Dto\HospitalConfiguration;
use App\DataFixtures\Structure\Dto\StructurePatternSet;

/**
 * Derives the profile of a hospital from its care tier, size and location and the structural patterns.
 *
 * A hospital draws one configuration (a coherent bundle of resources such as cath lab, stroke unit,
 * trauma level and obstetrics) by tier and size, then the number of further departments per field.
 * Both come from the hospital's own stream, so the same seed always gives the same hospital the same
 * structure. A ProfileSelection replaces the drawn configuration or single resources for targeted
 * scenarios. The closure frequency follows the size.
 */
final readonly class HospitalProfileResolver
{
    /** Average closure events per hospital and 30 days, by size. */
    private const array CLOSURE_EVENTS_PER_MONTH = ['Large' => 8.0, 'Medium' => 5.0, 'Small' => 3.0];

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
        $set = $this->structures->get();
        $tier = ($hospital->getTier() ?? HospitalTier::BASIC)->value;
        $size = ($hospital->getSize() ?? HospitalSize::MEDIUM)->value;
        $random = $this->streams->stream($seed, $hospital->getPublicIdString(), '-', RandomStreams::PROFILE);

        // Drawn even when a selection replaces it, so the departments below use the same numbers.
        $drawn = $this->sampler->sample($set->weightsFor($tier, $size), $random);
        $configuration = $this->select($set, $set->configuration($selection->configuration ?? $drawn), $selection);
        $resources = $configuration->resources;

        $offered = array_values(array_unique([...DepartmentVocabulary::CORE, ...DepartmentVocabulary::impliedBy($resources), ...$configuration->departments]));
        foreach (DepartmentVocabulary::FIELDS as $field => $departments) {
            $count = (int) $this->sampler->sample($configuration->fieldCounts[$field] ?? [0 => 1.0], $random);
            $required = DepartmentVocabulary::FIELD_REQUIRES[$field] ?? null;
            if ($required instanceof Capability && !$resources->provides($required)) {
                continue;
            }
            $further = array_values(array_diff($departments, $offered));
            array_push($offered, ...\array_slice($further, 0, $count));
        }

        return new HospitalProfile(
            segment: $this->segment($hospital),
            resources: $resources,
            resusShare: $set->resusShares[$resources->traumaLevel->value] ?? 0.0,
            missingDepartments: array_values(array_diff(DepartmentVocabulary::all(), $offered)),
            closureEventsPerMonth: self::CLOSURE_EVENTS_PER_MONTH[$size],
            configuration: $configuration->key,
            blockedIndications: $set->blockedIndications($resources),
        );
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

    private function segment(Hospital $hospital): string
    {
        return match (true) {
            HospitalTier::FULL === $hospital->getTier() => 'urban-full',
            HospitalLocation::RURAL === $hospital->getLocation() => 'rural-basic',
            default => 'urban-basic',
        };
    }
}
