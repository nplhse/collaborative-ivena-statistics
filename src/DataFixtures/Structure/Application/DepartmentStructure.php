<?php

declare(strict_types=1);

namespace App\DataFixtures\Structure\Application;

use App\DataFixtures\Generator\Application\Catalog\CatalogLookupType;
use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Structure\Dto\Capability;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;

/**
 * How departments make up a hospital structure, in the department keys of the generator vocabulary
 * (`GeneratorKeys`, #875).
 *
 * Every hospital offers the core. A resource implies its departments (a stroke unit implies the
 * stroke unit department and general neurology). The other departments of a field (speciality key)
 * are listed most common first: a hospital with two further departments of a field gets the first two.
 */
final class DepartmentStructure
{
    /** Department keys every hospital offers; they also take the cases of a missing department. */
    public const array CORE = ['cardiology', 'gastroenterology', 'pulmonology', 'general-surgery', 'trauma-surgery'];

    /** Further department keys by speciality key, most common first. The order of the fields is part of the random stream. */
    public const array FIELDS = [
        'internal-medicine' => ['nephrology', 'infectious-diseases'],
        'surgery' => ['visceral-surgery'],
        'orthopaedics' => ['joint-replacement', 'spine-surgery'],
        'neurology' => ['general-neurology', 'neurological-intensive-care', 'epileptology'],
        'psychiatry' => ['general-psychiatry', 'addiction-medicine', 'geriatric-psychiatry'],
        'paediatrics' => ['paediatric-cardiology', 'paediatric-gastroenterology'],
        'gynaecology-obstetrics' => ['gynaecology'],
    ];

    /** A field whose further departments need a resource. */
    public const array FIELD_REQUIRES = ['paediatrics' => Capability::PAEDIATRICS];

    /** Department keys that only a resource brings. */
    public const array IMPLIED = ['neurosurgery', 'stroke-unit', 'obstetrics', 'prenatal-medicine', 'neonatology', 'general-paediatrics'];

    /**
     * @return list<string> department keys
     */
    public static function all(): array
    {
        return array_values(array_unique([...self::CORE, ...array_merge(...array_values(self::FIELDS)), ...self::IMPLIED]));
    }

    /**
     * @return list<string> department keys
     */
    public static function impliedBy(HospitalResources $resources): array
    {
        $departments = [];
        if ($resources->neurosurgery) {
            $departments[] = 'neurosurgery';
        }
        if (StrokeUnitLevel::NONE !== $resources->strokeUnit) {
            $departments[] = 'stroke-unit';
            $departments[] = 'general-neurology';
        }
        if ($resources->obstetrics->rank() >= ObstetricsLevel::BASIC->rank()) {
            $departments[] = 'obstetrics';
        }
        if (ObstetricsLevel::PERINATAL === $resources->obstetrics) {
            $departments[] = 'prenatal-medicine';
            $departments[] = 'neonatology';
        }
        if ($resources->paediatrics) {
            $departments[] = 'general-paediatrics';
        }

        return $departments;
    }

    /**
     * The default labels of department keys, the names the patterns and closures still use (#875).
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public static function labels(array $keys): array
    {
        return array_map(static fn (string $key): string => GeneratorKeys::label(CatalogLookupType::Department, $key), $keys);
    }
}
