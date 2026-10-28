<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Generator\Application\HospitalProfile;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;
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

    public function testFewSmallBasicHospitalsAndMostLargeExtendedHospitalsHaveACathLab(): void
    {
        $share = fn (HospitalTier $tier, HospitalSize $size): float => \count(array_filter(
            $this->hospitals(400, $tier, $size),
            fn (Hospital $hospital): bool => $this->resolver()->resolve($hospital, 740)->hasCathlab,
        )) / 400;

        self::assertGreaterThan(0.0, $share(HospitalTier::BASIC, HospitalSize::SMALL), 'some small hospitals have one');
        self::assertLessThan(0.12, $share(HospitalTier::BASIC, HospitalSize::SMALL));
        self::assertGreaterThan(0.7, $share(HospitalTier::EXTENDED, HospitalSize::LARGE));
    }

    public function testTheSameSeedGivesTheSameProfile(): void
    {
        $hospitals = $this->hospitals(100, HospitalTier::EXTENDED, HospitalSize::MEDIUM);
        $profiles = fn (int $seed): array => array_map(fn (Hospital $hospital): HospitalProfile => $this->resolver()->resolve($hospital, $seed), $hospitals);

        self::assertEquals($profiles(740), $profiles(740));
        self::assertNotEquals($profiles(740), $profiles(741));
    }

    public function testFullCareOffersMoreDepartmentsThanBasicCareAndEveryHospitalTheCore(): void
    {
        $missing = function (HospitalTier $tier, HospitalSize $size): float {
            $count = 0;
            foreach ($this->hospitals(200, $tier, $size) as $hospital) {
                $profile = $this->resolver()->resolve($hospital, 740);
                foreach (HospitalProfileResolver::CORE_DEPARTMENTS as $department) {
                    self::assertTrue($profile->offers($department), $department);
                }
                $count += \count($profile->missingDepartments);
            }

            return $count / 200;
        };

        self::assertLessThan(1.5, $missing(HospitalTier::FULL, HospitalSize::LARGE), 'full care offers nearly everything');
        self::assertLessThan($missing(HospitalTier::EXTENDED, HospitalSize::MEDIUM), $missing(HospitalTier::FULL, HospitalSize::MEDIUM));
        self::assertLessThan($missing(HospitalTier::BASIC, HospitalSize::MEDIUM), $missing(HospitalTier::EXTENDED, HospitalSize::MEDIUM));
        self::assertLessThan($missing(HospitalTier::BASIC, HospitalSize::SMALL), $missing(HospitalTier::BASIC, HospitalSize::LARGE), 'larger hospitals offer more');
    }

    public function testTheClosureFrequencyFollowsTheSize(): void
    {
        $events = fn (HospitalSize $size): float => $this->resolver()->resolve($this->hospitals(1, HospitalTier::BASIC, $size)[0], 740)->closureEventsPerMonth;

        self::assertSame([8.0, 5.0, 3.0], [$events(HospitalSize::LARGE), $events(HospitalSize::MEDIUM), $events(HospitalSize::SMALL)], 'the same rates as before the profile had them');
    }

    public function testResusCasesAreRareButPossibleInSmallHospitals(): void
    {
        $small = $this->resolver()->resolve($this->hospitals(1, HospitalTier::BASIC, HospitalSize::SMALL)[0], 740);
        $full = $this->resolver()->resolve($this->hospitals(1, HospitalTier::FULL, HospitalSize::LARGE)[0], 740);

        self::assertGreaterThan(0.0, $small->resusShare);
        self::assertLessThan($full->resusShare, $small->resusShare);
    }

    public function testAHospitalWithoutCathLabGetsNoCathLabCases(): void
    {
        $pattern = new DefaultDistributionPatterns()->get('urban-basic');

        $without = new HospitalProfile('urban-basic', false, 0.01)->applyTo($pattern)->flagProbabilities();
        $with = new HospitalProfile('urban-basic', true, 0.01)->applyTo($pattern)->flagProbabilities();

        self::assertSame(0.0, $without['requires_cathlab']);
        self::assertSame($pattern->flagProbabilities()['requires_cathlab'], $with['requires_cathlab']);
        self::assertSame(0.01, $without['requires_resus']);
        self::assertSame($pattern->flagProbabilities()['is_cpr'], $without['is_cpr'], 'other flags stay');
        self::assertSame(['Geburtshilfe'], new HospitalProfile('urban-basic', true, 0.01, ['Geburtshilfe'])->applyTo($pattern)->departmentsNotOffered());
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

    private function resolver(): HospitalProfileResolver
    {
        return new HospitalProfileResolver(new RandomStreams());
    }
}
