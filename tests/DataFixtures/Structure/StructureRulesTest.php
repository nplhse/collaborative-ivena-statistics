<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Structure;

use App\DataFixtures\Structure\Application\DefaultStructurePatterns;
use App\DataFixtures\Structure\Application\DepartmentVocabulary;
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

    public function testTheVocabularyCoversTheGeneratorDepartments(): void
    {
        self::assertCount(25, DepartmentVocabulary::all());
        self::assertSame(['Neurochirurgie', 'Schlaganfallmedizin', 'Allgemeine Neurologie', 'Geburtshilfe', 'Pränatalmedizin', 'Neonatologie', 'Allgemeine Pädiatrie'], DepartmentVocabulary::impliedBy(new HospitalResources(
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
            departments: ['Schlaganfallmedizin', 'Zahnmedizin'],
            fieldCounts: [
                'Chirurgie' => [0 => 0.5, 2 => 0.4],
                'Pädiatrie' => [1 => 1.0],
                'Dermatologie' => [0 => 1.0],
            ],
        );

        self::assertSame([
            'broken: a supraregional trauma centre needs neurosurgery',
            'broken: a perinatal centre needs paediatrics',
            'broken: "Schlaganfallmedizin" follows from a resource and cannot be listed on its own',
            'broken: unknown department "Zahnmedizin"',
            'broken: field "Chirurgie": shares sum to 0.900, not 1',
            'broken: field "Chirurgie": 2 departments, but the field has 1',
            'broken: field "Pädiatrie" needs paediatrics',
            'broken: unknown field "Dermatologie"',
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
