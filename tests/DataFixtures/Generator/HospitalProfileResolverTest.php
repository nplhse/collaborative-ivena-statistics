<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\FixtureVolume;
use App\DataFixtures\Generator\Application\HospitalProfile;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\DataFixtures\Generator\Application\ProfileSelection;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;
use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use App\DataFixtures\Structure\Application\DefaultStructurePatterns;
use App\DataFixtures\Structure\Application\DepartmentStructure;
use App\DataFixtures\Structure\Application\StructureRules;
use App\DataFixtures\Structure\Dto\HospitalResources;
use App\DataFixtures\Structure\Dto\ObstetricsLevel;
use App\DataFixtures\Structure\Dto\StrokeUnitLevel;
use App\DataFixtures\Structure\Dto\TraumaLevel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HospitalProfileResolverTest extends TestCase
{
    public function testEveryFullCareHospitalHasACathLab(): void
    {
        foreach ($this->hospitals(50, HospitalTier::FULL, HospitalSize::MEDIUM) as $hospital) {
            self::assertTrue($this->resolver()->resolve($hospital, 740)->hasCathlab);
        }
    }

    /**
     * Basic/Small draws `basic-cardio` with weight 0.10 and Extended/Medium every configuration but
     * `extended-no-cathlab` (0.10), so about 10 % and 90 % have a cath lab.
     */
    public function testFewSmallBasicHospitalsAndMostMediumExtendedHospitalsHaveACathLab(): void
    {
        $share = fn (HospitalTier $tier, HospitalSize $size): float => \count(array_filter(
            $this->hospitals(400, $tier, $size),
            fn (Hospital $hospital): bool => $this->resolver()->resolve($hospital, 740)->hasCathlab,
        )) / 400;

        self::assertEqualsWithDelta(0.10, $share(HospitalTier::BASIC, HospitalSize::SMALL), 0.04);
        self::assertEqualsWithDelta(0.90, $share(HospitalTier::EXTENDED, HospitalSize::MEDIUM), 0.04);
    }

    public function testASmallHospitalCanGetASpecialisedCapability(): void
    {
        $configurations = array_map(
            fn (Hospital $hospital): string => $this->resolver()->resolve($hospital, 740)->configuration,
            $this->hospitals(400, HospitalTier::BASIC, HospitalSize::SMALL),
        );

        self::assertContains('basic-neurosurgery', $configurations, 'uncommon, weight 0.05');
        self::assertContains('basic-stroke', $configurations);
        self::assertGreaterThan(\count(array_keys($configurations, 'basic-neurosurgery', true)), \count(array_keys($configurations, 'basic-general', true)), 'common configurations occur more often');
    }

    public function testTheSameSeedGivesTheSameProfile(): void
    {
        $hospitals = $this->hospitals(100, HospitalTier::EXTENDED, HospitalSize::MEDIUM);
        $profiles = fn (int $seed): array => array_map(fn (Hospital $hospital): HospitalProfile => $this->resolver()->resolve($hospital, $seed), $hospitals);

        self::assertEquals($profiles(740), $profiles(740));
        self::assertNotEquals($profiles(740), $profiles(741));
    }

    /**
     * Departments follow the resources: a stroke unit brings stroke medicine and neurology,
     * neurosurgery its department, obstetrics the delivery ward, a perinatal centre neonatology, and
     * without paediatrics there is no paediatric department.
     */
    public function testEveryDrawnStructureIsCoherent(): void
    {
        foreach ([HospitalTier::BASIC, HospitalTier::EXTENDED, HospitalTier::FULL] as $tier) {
            foreach ([HospitalSize::SMALL, HospitalSize::MEDIUM, HospitalSize::LARGE] as $size) {
                foreach ($this->hospitals(100, $tier, $size) as $hospital) {
                    $profile = $this->resolver()->resolve($hospital, 740);
                    $resources = $profile->resources;
                    $label = $profile->configuration;

                    self::assertSame([], StructureRules::resourceViolations($resources), $label);
                    foreach (DepartmentStructure::labels(DepartmentStructure::CORE) as $department) {
                        self::assertTrue($profile->offers($department), $department);
                    }
                    self::assertSame(StrokeUnitLevel::NONE !== $resources->strokeUnit, $profile->offers('Schlaganfallmedizin'), $label);
                    if (StrokeUnitLevel::NONE !== $resources->strokeUnit) {
                        self::assertTrue($profile->offers('Allgemeine Neurologie'), $label);
                    }
                    self::assertSame($resources->neurosurgery, $profile->offers('Neurochirurgie'), $label);
                    self::assertSame(ObstetricsLevel::NONE !== $resources->obstetrics, $profile->offers('Geburtshilfe'), $label);
                    self::assertSame(ObstetricsLevel::PERINATAL === $resources->obstetrics, $profile->offers('Neonatologie'), $label);
                    foreach (['Allgemeine Pädiatrie', 'Kinderkardiologie', 'Kindergastroenterologie'] as $department) {
                        if (!$resources->paediatrics) {
                            self::assertFalse($profile->offers($department), $label.' '.$department);
                        }
                    }
                }
            }
        }
    }

    /**
     * `full-maximum` has two further internal medicine departments and one further surgical one in
     * every draw, so it always offers nephrology, infectiology and visceral surgery.
     */
    public function testFieldCountsPickTheMostCommonDepartmentsOfAField(): void
    {
        foreach ($this->hospitals(50, HospitalTier::FULL, HospitalSize::LARGE) as $hospital) {
            $profile = $this->resolver()->resolve($hospital, 740, new ProfileSelection(configuration: 'full-maximum'));

            foreach (['Nephrologie', 'Infektiologie', 'Viszeralchirurgie', 'Neurochirurgie', 'Pränatalmedizin', 'Neonatologie'] as $department) {
                self::assertTrue($profile->offers($department), $department);
            }
        }
    }

    public function testFullCareOffersMoreDepartmentsThanBasicCare(): void
    {
        $missing = function (HospitalTier $tier, HospitalSize $size): float {
            $count = 0;
            foreach ($this->hospitals(200, $tier, $size) as $hospital) {
                $count += \count($this->resolver()->resolve($hospital, 740)->missingDepartments);
            }

            return $count / 200;
        };

        self::assertLessThan(4.0, $missing(HospitalTier::FULL, HospitalSize::LARGE), 'full care offers nearly everything');
        self::assertLessThan($missing(HospitalTier::EXTENDED, HospitalSize::MEDIUM), $missing(HospitalTier::FULL, HospitalSize::MEDIUM));
        self::assertLessThan($missing(HospitalTier::BASIC, HospitalSize::MEDIUM), $missing(HospitalTier::EXTENDED, HospitalSize::MEDIUM));
    }

    public function testAnUncommonConfigurationCanBeSelectedExplicitly(): void
    {
        $hospital = $this->hospitals(1, HospitalTier::BASIC, HospitalSize::SMALL)[0];

        $profile = $this->resolver()->resolve($hospital, 740, new ProfileSelection(configuration: 'basic-neurosurgery'));

        self::assertSame('basic-neurosurgery', $profile->configuration);
        self::assertTrue($profile->resources->neurosurgery);
        self::assertTrue($profile->offers('Neurochirurgie'));
        self::assertSame(TraumaLevel::REGIONAL, $profile->resources->traumaLevel);
        self::assertSame(0.05, $profile->resusShare, 'resuscitation room share of a regional trauma centre');
        self::assertNotContains('211', $profile->blockedIndications, 'a regional trauma centre takes polytrauma');
    }

    public function testASingleResourceCanBeSwitchedOn(): void
    {
        $hospital = $this->hospitals(1, HospitalTier::BASIC, HospitalSize::SMALL)[0];

        $profile = $this->resolver()->resolve($hospital, 740, new ProfileSelection(configuration: 'basic-general', cathlab: true));

        self::assertTrue($profile->hasCathlab);
        self::assertSame(['211', '212'], array_values(array_intersect($profile->blockedIndications, ['211', '212', '332', '346'])), 'STEMI and cardiogenic shock are no longer blocked');
    }

    public function testCapabilityGatesBlockWhatTheHospitalLacks(): void
    {
        $basic = $this->resolver()->resolve($this->hospitals(1, HospitalTier::BASIC, HospitalSize::MEDIUM)[0], 740, new ProfileSelection(configuration: 'basic-general'));
        $full = $this->resolver()->resolve($this->hospitals(1, HospitalTier::FULL, HospitalSize::LARGE)[0], 740, new ProfileSelection(configuration: 'full-no-obstetrics'));

        self::assertSame(['211', '212', '332', '346'], \array_slice($basic->blockedIndications, 0, 4));
        self::assertSame(0.02, $basic->resusShare);
        self::assertNotContains('332', $full->blockedIndications);
        self::assertContains('525', $full->blockedIndications, 'no preterm births without a perinatal centre');
        self::assertSame(0.10, $full->resusShare);
    }

    public function testAnIncoherentOrUnknownSelectionIsRefused(): void
    {
        $hospital = $this->hospitals(1, HospitalTier::BASIC, HospitalSize::SMALL)[0];

        try {
            $this->resolver()->resolve($hospital, 740, new ProfileSelection(configuration: 'basic-general', traumaLevel: TraumaLevel::SUPRA));
            self::fail('a supraregional trauma centre without neurosurgery');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('needs neurosurgery', $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Unknown hospital configuration "small-university"');
        $this->resolver()->resolve($hospital, 740, new ProfileSelection(configuration: 'small-university'));
    }

    /**
     * Volume and tilt are drawn after the configuration and departments, so a hospital keeps the
     * structure it had before; only beds and an explicit volume change its rate.
     */
    public function testTheVolumeFollowsTheBedsAndAnExplicitAnnualVolumeReplacesIt(): void
    {
        $hospital = $this->hospitals(1, HospitalTier::EXTENDED, HospitalSize::MEDIUM)[0];
        $volume = $this->volume();

        $reference = $this->resolver()->resolve($hospital, 740, new ProfileSelection(beds: 450));
        $double = $this->resolver()->resolve($hospital, 740, new ProfileSelection(beds: 900));
        $explicit = $this->resolver()->resolve($hospital, 740, new ProfileSelection(beds: 900, annualVolume: 7300));

        self::assertEqualsWithDelta(2 ** 0.8, $double->volumeFactor / $reference->volumeFactor, 1e-9, 'twice the reference beds: 2 ^ 0.8 = 1.74 times the cases');
        self::assertEqualsWithDelta(15.0 * $reference->volumeFactor, $reference->dailyRate($volume), 1e-9, 'preset rate of a medium hospital times its factor');
        self::assertSame(20.0, $explicit->dailyRate($volume), '7,300 a year are 20 a day, whatever beds and factor say');
        self::assertSame($reference->configuration, $double->configuration);
        self::assertSame($reference->missingDepartments, $explicit->missingDepartments);
        self::assertEquals($reference->tilt, $explicit->tilt);
    }

    /**
     * The hospital factor is log-normal with mean 1 and spread 0.20: hospitals of one size and with
     * the same beds differ by about a fifth.
     */
    public function testHospitalsOfOneSizeDifferInVolumeAndMix(): void
    {
        $factors = [];
        $trauma = [];
        foreach ($this->hospitals(400, HospitalTier::EXTENDED, HospitalSize::MEDIUM) as $hospital) {
            $profile = $this->resolver()->resolve($hospital, 740, new ProfileSelection(beds: 450));
            $factors[] = $profile->volumeFactor;
            $trauma[] = log($profile->tilt->categories[2]);
            self::assertCount(8, $profile->tilt->categories);
        }

        $mean = array_sum($factors) / \count($factors);
        $deviation = sqrt(array_sum(array_map(static fn (float $factor): float => ($factor - $mean) ** 2, $factors)) / \count($factors));
        self::assertEqualsWithDelta(1.0, $mean, 0.04);
        self::assertEqualsWithDelta(0.20, $deviation / $mean, 0.03);

        $traumaMean = array_sum($trauma) / \count($trauma);
        self::assertEqualsWithDelta(0.25, sqrt(array_sum(array_map(static fn (float $log): float => ($log - $traumaMean) ** 2, $trauma)) / \count($trauma)), 0.03, 'category spread 0.25');
    }

    public function testTierAndSizeCanBeSelectedForANewHospital(): void
    {
        $hospital = $this->hospitals(1, HospitalTier::BASIC, HospitalSize::SMALL)[0];

        $profile = $this->resolver()->resolve($hospital, 740, new ProfileSelection(tier: HospitalTier::FULL, size: HospitalSize::LARGE));

        self::assertSame('urban-full', $profile->segment);
        self::assertStringStartsWith('full-', $profile->configuration);
        self::assertSame('Large', $profile->size);
        self::assertSame(0.10, $profile->closureShare, 'default share of closed department-hours of a large hospital');
    }

    public function testTheClosureShareFollowsTheSizeOrTheSelection(): void
    {
        $share = fn (HospitalSize $size, ?float $selected = null): float => $this->resolver()->resolve($this->hospitals(1, HospitalTier::BASIC, $size)[0], 740, new ProfileSelection(closureShare: $selected))->closureShare;

        self::assertSame([0.05, 0.08, 0.10], [$share(HospitalSize::SMALL), $share(HospitalSize::MEDIUM), $share(HospitalSize::LARGE)]);
        self::assertSame(0.25, $share(HospitalSize::SMALL, 0.25));
    }

    public function testFiguresOutOfRangeAreRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('beds must be more than 0; the annual volume must be more than 0; the closure share must lie from 0 to below 1');

        $this->resolver()->resolve($this->hospitals(1, HospitalTier::BASIC, HospitalSize::SMALL)[0], 740, new ProfileSelection(beds: 0, annualVolume: -1, closureShare: 1.0));
    }

    public function testTheProfileAdjustsThePattern(): void
    {
        $pattern = new DefaultDistributionPatterns()->get('urban-basic');

        $without = new HospitalProfile('urban-basic', new HospitalResources(), 0.01)->applyTo($pattern)->flagProbabilities();
        $with = new HospitalProfile('urban-basic', new HospitalResources(cathlab: true), 0.01)->applyTo($pattern)->flagProbabilities();
        $blocked = new HospitalProfile('urban-basic', new HospitalResources(), 0.01, ['Geburtshilfe'], blockedIndications: ['332'])->applyTo($pattern);

        self::assertSame(0.0, $without['requires_cathlab']);
        self::assertSame($pattern->flagProbabilities()['requires_cathlab'], $with['requires_cathlab']);
        self::assertSame(0.01, $without['requires_resus']);
        self::assertSame($pattern->flagProbabilities()['is_cpr'], $without['is_cpr'], 'other flags stay');
        self::assertSame(['Geburtshilfe'], $blocked->departmentsNotOffered());
        self::assertTrue($blocked->blocksIndication('332'));
        self::assertFalse($blocked->blocksIndication('331'));
    }

    /**
     * @return list<Hospital>
     */
    private function hospitals(int $count, HospitalTier $tier, HospitalSize $size): array
    {
        $hospitals = [];
        for ($i = 0; $i < $count; ++$i) {
            $hospitals[] = new Hospital()
                ->setName(sprintf('%s %s %d', $tier->value, $size->value, $i))
                ->setTier($tier)
                ->setSize($size)
                ->setLocation(HospitalLocation::URBAN)
                ->setPublicId(Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_OID), sprintf('%s-%s-%d', $tier->value, $size->value, $i)));
        }

        return $hospitals;
    }

    private function volume(): FixtureVolume
    {
        return new FixtureVolume('test', ['Medium' => 1], 12, ['Small' => 10.0, 'Medium' => 15.0, 'Large' => 40.0], 1.0, 0.0, 740, false);
    }

    private function resolver(): HospitalProfileResolver
    {
        return new HospitalProfileResolver(new RandomStreams(), new WeightedCategoricalSampler(), new DefaultStructurePatterns());
    }
}
