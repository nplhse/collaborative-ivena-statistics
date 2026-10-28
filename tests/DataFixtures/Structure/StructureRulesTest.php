<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Structure;

use App\DataFixtures\Generator\Application\Catalog\GeneratorKeys;
use App\DataFixtures\Structure\Application\DefaultStructurePatterns;
use App\DataFixtures\Structure\Application\DepartmentStructure;
use App\DataFixtures\Structure\Application\StructureRules;
use App\DataFixtures\Structure\Dto\Capability;
use App\DataFixtures\Structure\Dto\HospitalConfiguration;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;
use App\DataFixtures\Structure\Dto\StructurePatternSet;
use App\DataFixtures\Structure\Dto\TraumaLevel;
use PHPUnit\Framework\TestCase;

final class StructureRulesTest extends TestCase
{
    public function testTheCodeDefaultsAreCoherent(): void
    {
        self::assertSame([], StructureRules::setViolations(new DefaultStructurePatterns()->get()));
    }

    /**
     * The weights of one tier and size are shares in five percent steps that sum to 1.
     */
    public function testTheDefaultWeightsOfEveryTierAndSizeSumToOne(): void
    {
        $set = new DefaultStructurePatterns()->get();
        foreach (StructureRules::TIERS as $tier) {
            foreach (StructureRules::SIZES as $size) {
                self::assertEqualsWithDelta(1.0, array_sum($set->weightsFor($tier, $size)), 0.0001, $tier.'/'.$size);
            }
        }
        self::assertSame(['basic-general' => 0.55, 'basic-obstetrics' => 0.20, 'basic-cardio' => 0.10, 'basic-stroke' => 0.10, 'basic-neurosurgery' => 0.05], $set->weightsFor('Basic', 'Small'));
    }

    /**
     * The structure uses the department keys of the generator vocabulary (#875), every field is the
     * speciality key its departments are written with, and every generator department has a place.
     */
    public function testTheStructureUsesTheGeneratorKeys(): void
    {
        self::assertEqualsCanonicalizing(array_keys(GeneratorKeys::DEPARTMENTS), DepartmentStructure::all());
        foreach (DepartmentStructure::FIELDS as $field => $departments) {
            foreach ($departments as $department) {
                self::assertSame($field, GeneratorKeys::DEPARTMENTS[$department]['speciality'], $department);
            }
        }
        self::assertSame(['Kardiologie', 'Gastroenterologie', 'Pulmologie', 'Allgemeinchirurgie', 'Unfallchirurgie'], DepartmentStructure::labels(DepartmentStructure::CORE));
        self::assertSame(['neurosurgery', 'stroke-unit', 'general-neurology', 'obstetrics', 'prenatal-medicine', 'neonatology', 'general-paediatrics'], DepartmentStructure::impliedBy(new HospitalResources(
            neurosurgery: true,
            strokeUnit: StrokeUnitLevel::REGIONAL,
            obstetrics: ObstetricsLevel::PERINATAL,
            paediatrics: true,
        )));
    }

    public function testIncoherentConfigurationsAreReported(): void
    {
        $configuration = new HospitalConfiguration(
            key: 'broken',
            weights: ['Basic' => ['Tiny' => 1.0]],
            resources: new HospitalResources(traumaLevel: TraumaLevel::SUPRA, obstetrics: ObstetricsLevel::PERINATAL),
            departments: ['stroke-unit', 'dentistry'],
            fieldCounts: [
                'surgery' => [0 => 0.5, 2 => 0.4],
                'paediatrics' => [1 => 1.0],
                'dermatology' => [0 => 1.0],
            ],
        );

        self::assertSame([
            'broken: a supraregional trauma centre needs neurosurgery',
            'broken: a perinatal centre needs paediatrics',
            'broken: "stroke-unit" follows from a resource and cannot be listed on its own',
            'broken: unknown department "dentistry"',
            'broken: field "surgery": shares sum to 0.900, not 1',
            'broken: field "surgery": 2 departments, but the field has 1',
            'broken: field "paediatrics" needs paediatrics',
            'broken: unknown field "dermatology"',
            'broken: unknown tier or size "Basic/Tiny"',
        ], StructureRules::configurationViolations($configuration));
    }

    public function testASetNeedsAConfigurationForEveryTierAndSize(): void
    {
        $set = new StructurePatternSet(
            [new HospitalConfiguration('only-basic', ['Basic' => ['Small' => 1.0, 'Medium' => 1.0, 'Large' => 1.0]], new HospitalResources())],
            gates: ['332' => Capability::CATHLAB],
            resusShares: ['basic' => 0.02],
        );

        self::assertSame([
            'no configuration for Extended/Small', 'no configuration for Extended/Medium', 'no configuration for Extended/Large',
            'no configuration for Full/Small', 'no configuration for Full/Medium', 'no configuration for Full/Large',
        ], StructureRules::setViolations($set));
        self::assertSame(['332'], $set->blockedIndications(new HospitalResources()));
        self::assertSame([], $set->blockedIndications(new HospitalResources(cathlab: true)));
    }
}
