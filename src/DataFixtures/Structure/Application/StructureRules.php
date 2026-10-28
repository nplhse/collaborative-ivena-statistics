<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Application;

use App\DataFixtures\Structure\Dto\Capability;
use App\DataFixtures\Structure\Dto\HospitalConfiguration;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StructurePatternSet;
use App\DataFixtures\Structure\Dto\TraumaLevel;

/**
 * Relations a hospital structure must keep, whatever the source of the patterns.
 *
 * - a supraregional trauma centre has neurosurgery,
 * - a perinatal centre has paediatrics,
 * - departments that only a resource brings (stroke medicine, neurosurgery, obstetrics, neonatology,
 *   paediatrics) are never listed on their own,
 * - field counts stay within the departments of the field, and a paediatric field needs paediatrics,
 * - shares are not negative and sum to 1, and every tier and size has a configuration to draw.
 */
final class StructureRules
{
    public const array TIERS = ['Basic', 'Extended', 'Full'];

    public const array SIZES = ['Small', 'Medium', 'Large'];

    private const float TOLERANCE = 0.001;

    /**
     * @return list<string> what is wrong, empty when the resources are coherent
     */
    public static function resourceViolations(HospitalResources $resources): array
    {
        $violations = [];
        if (TraumaLevel::SUPRA === $resources->traumaLevel && !$resources->neurosurgery) {
            $violations[] = 'a supraregional trauma centre needs neurosurgery';
        }
        if (ObstetricsLevel::PERINATAL === $resources->obstetrics && !$resources->paediatrics) {
            $violations[] = 'a perinatal centre needs paediatrics';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function configurationViolations(HospitalConfiguration $configuration): array
    {
        $violations = self::resourceViolations($configuration->resources);
        $known = DepartmentStructure::all();
        foreach ($configuration->departments as $department) {
            if (!\in_array($department, $known, true)) {
                $violations[] = sprintf('unknown department "%s"', $department);
            } elseif (\in_array($department, DepartmentStructure::IMPLIED, true)) {
                $violations[] = sprintf('"%s" follows from a resource and cannot be listed on its own', $department);
            }
        }

        foreach ($configuration->fieldCounts as $field => $counts) {
            $departments = DepartmentStructure::FIELDS[$field] ?? null;
            if (null === $departments) {
                $violations[] = sprintf('unknown field "%s"', $field);
                continue;
            }
            $violations = [...$violations, ...self::shareViolations(sprintf('field "%s"', $field), $counts)];
            foreach ($counts as $count => $share) {
                if ($share <= 0.0) {
                    continue;
                }
                if ($count < 0 || $count > \count($departments)) {
                    $violations[] = sprintf('field "%s": %d departments, but the field has %d', $field, $count, \count($departments));
                }
                $required = DepartmentStructure::FIELD_REQUIRES[$field] ?? null;
                if ($count > 0 && $required instanceof Capability && !$configuration->resources->provides($required)) {
                    $violations[] = sprintf('field "%s" needs %s', $field, $required->value);
                }
            }
        }

        foreach ($configuration->weights as $tier => $sizes) {
            foreach ($sizes as $size => $weight) {
                if (!\in_array($tier, self::TIERS, true) || !\in_array($size, self::SIZES, true)) {
                    $violations[] = sprintf('unknown tier or size "%s/%s"', $tier, $size);
                } elseif ($weight < 0.0) {
                    $violations[] = sprintf('negative weight for %s/%s', $tier, $size);
                }
            }
        }

        return array_map(static fn (string $violation): string => sprintf('%s: %s', $configuration->key, $violation), $violations);
    }

    /**
     * @return list<string>
     */
    public static function setViolations(StructurePatternSet $set): array
    {
        $violations = [];
        foreach ($set->configurations as $configuration) {
            $violations = [...$violations, ...self::configurationViolations($configuration)];
        }
        foreach (self::TIERS as $tier) {
            foreach (self::SIZES as $size) {
                if ([] === $set->weightsFor($tier, $size)) {
                    $violations[] = sprintf('no configuration for %s/%s', $tier, $size);
                }
            }
        }
        foreach ($set->volume->referenceBeds as $size => $beds) {
            if (!\in_array($size, self::SIZES, true) || $beds <= 0) {
                $violations[] = sprintf('volume: reference beds for "%s" must be a size with more than 0 beds', $size);
            }
        }
        foreach (['bed elasticity' => $set->volume->bedElasticity, 'hospital spread' => $set->volume->hospitalSpread, 'category spread' => $set->mixVariation->categorySpread, 'old age spread' => $set->mixVariation->oldAgeSpread, 'urgent spread' => $set->mixVariation->urgentSpread] as $name => $value) {
            if ($value < 0.0 || $value > 2.0) {
                $violations[] = sprintf('%s must lie between 0 and 2', $name);
            }
        }
        foreach ($set->closureShares as $size => $share) {
            if (!\in_array($size, self::SIZES, true) || $share < 0.0 || $share >= 1.0) {
                $violations[] = sprintf('closure share for "%s" must be a size with a share from 0 to below 1', $size);
            }
        }

        return $violations;
    }

    /**
     * @param array<int|string, float> $shares
     *
     * @return list<string>
     */
    private static function shareViolations(string $label, array $shares): array
    {
        foreach ($shares as $share) {
            if ($share < 0.0) {
                return [sprintf('%s: negative share', $label)];
            }
        }

        $sum = (float) array_sum($shares);

        return abs($sum - 1.0) > self::TOLERANCE ? [sprintf('%s: shares sum to %.3f, not 1', $label, $sum)] : [];
    }
}
