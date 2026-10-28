<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\Allocation\Infrastructure\Faker\Provider\PzcCodes;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use App\DataFixtures\Pattern\Application\CoherenceRules;
use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;
use App\DataFixtures\Pattern\Application\PatternSampler;
use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class DefaultDistributionPatternsTest extends KernelTestCase
{
    public function testEveryPatternSamplesAgainstTheSyntheticCatalog(): void
    {
        self::bootKernel();
        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');
        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['reference']), true);

        /** @var PatternSampler $sampler */
        $sampler = $container->get(PatternSampler::class);
        $patterns = new DefaultDistributionPatterns();
        $start = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2026-03-31');
        $random = new RandomStreams()->stream(740, 'patterns', '2026-01', RandomStreams::ALLOCATIONS);

        foreach (DefaultDistributionPatterns::names() as $name) {
            $urgent = 0;
            for ($i = 0; $i < 500; ++$i) {
                $sampled = $sampler->sample($patterns->get($name), $start, $end, $random);
                self::assertNotNull($sampled->indicationNormalized, $name);
                self::assertSame($sampled->indicationNormalized->getId(), $sampled->indicationRaw->getTarget()?->getId(), 'the raw label maps to the sampled indication');
                self::assertGreaterThan($sampled->createdAt, $sampled->arrivalAt);
                $this->assertCoherent($sampled);
                $urgent += AllocationUrgency::EMERGENCY === $sampled->urgency ? 1 : 0;
            }

            self::assertLessThan(250, $urgent, $name.': urgency is not uniform, most cases are not emergencies');
        }
    }

    /**
     * No impossible combinations, whatever the distributions say (ADR 022).
     */
    private function assertCoherent(SampledAllocationAttributes $sampled): void
    {
        $label = sprintf('%s, %d, %s, %s', $sampled->indicationNormalized?->getName(), $sampled->age, $sampled->gender->value, $sampled->department->getName());
        self::assertSame(MedicalSpecialities::fieldOfUnit((string) $sampled->department->getName()), $sampled->speciality->getName(), 'the speciality is the field of the department: '.$label);
        if ($sampled->isPregnant) {
            self::assertSame(AllocationGender::FEMALE, $sampled->gender, 'pregnant: '.$label);
            self::assertGreaterThanOrEqual(15, $sampled->age, 'pregnant: '.$label);
            self::assertLessThanOrEqual(49, $sampled->age, 'pregnant: '.$label);
        }
        if (CoherenceRules::PAEDIATRICS === $sampled->speciality->getName()) {
            self::assertLessThan(18, $sampled->age, 'paediatrics: '.$label);
        }
        if ($sampled->isCpr) {
            self::assertSame(AllocationUrgency::EMERGENCY, $sampled->urgency, 'CPR: '.$label);
            self::assertTrue($sampled->requiresResus, 'CPR: '.$label);
        }
        if ($sampled->isShock) {
            self::assertSame(AllocationUrgency::EMERGENCY, $sampled->urgency, 'shock: '.$label);
        }
        if ('Schlaganfall / Blutung < 24 h oder unklar' === $sampled->indicationNormalized?->getName()) {
            self::assertContains($sampled->speciality->getName(), ['Neurologie', 'Chirurgie'], 'stroke: '.$label);
        }
    }

    /**
     * 521, 531 and 541 share the wording "<16 SSW"; the sampler keeps them apart (#864).
     */
    public function testCodesWithTheSameWordingStayApart(): void
    {
        self::bootKernel();
        $sampler = $this->loadReferenceCatalog();
        $pattern = new DefaultDistributionPatterns()->get('urban-full')
            ->withDistribution('indication_normalized', ['_present' => 1.0, '521' => 0.5, '531' => 0.3, '541' => 0.2]);
        $random = new RandomStreams()->stream(740, 'obstetrics', '2026-01', RandomStreams::ALLOCATIONS);

        $codes = [];
        for ($i = 0; $i < 200; ++$i) {
            $sampled = $sampler->sample($pattern, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), $random);
            self::assertNotNull($sampled->indicationNormalized);
            self::assertSame('<16 SSW', $sampled->indicationNormalized->getName());
            self::assertSame($sampled->indicationNormalized->getId(), $sampled->indicationRaw->getTarget()?->getId(), 'the raw label maps to the sampled code');
            self::assertContains($sampled->department->getName(), ['Geburtshilfe', 'Pränatalmedizin', 'Gynäkologie']);
            self::assertSame(AllocationGender::FEMALE, $sampled->gender);
            self::assertTrue($sampled->isPregnant, 'obstetric cases are pregnant');
            $codes[(int) $sampled->indicationNormalized->getCode()] = ($codes[(int) $sampled->indicationNormalized->getCode()] ?? 0) + 1;
        }

        ksort($codes);
        self::assertSame([521, 531, 541], array_keys($codes), 'all three codes are drawn');
        self::assertGreaterThan($codes[541], $codes[521], 'by their shares');
    }

    public function testAHospitalGetsOnlyDepartmentsItOffers(): void
    {
        self::bootKernel();
        $sampler = $this->loadReferenceCatalog();
        $missing = ['Geburtshilfe', 'Pränatalmedizin', 'Schlaganfallmedizin', 'Allgemeine Neurologie', 'Neurochirurgie', 'Allgemeine Pädiatrie', 'Neonatologie', 'Kinderkardiologie', 'Kindergastroenterologie'];
        $pattern = new DefaultDistributionPatterns()->get('urban-full')->withoutDepartments($missing);
        $random = new RandomStreams()->stream(740, 'offered', '2026-01', RandomStreams::ALLOCATIONS);

        $obstetric = 0;
        for ($i = 0; $i < 1000; ++$i) {
            $sampled = $sampler->sample($pattern, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), $random);
            self::assertNotContains($sampled->department->getName(), $missing);
            $obstetric += \in_array(substr((string) $sampled->indicationNormalized?->getCode(), 0, 2), ['52', '53', '54'], true) ? 1 : 0;
        }
        self::assertSame(0, $obstetric, 'without obstetrics the pregnancy codes are drawn again');

        // Only core departments left: every case still gets one of them.
        $onlyCore = new DefaultDistributionPatterns()->get('urban-full')->withoutDepartments(array_values(array_diff(MedicalSpecialities::unitNames(), HospitalProfileResolver::CORE_DEPARTMENTS)));
        for ($i = 0; $i < 300; ++$i) {
            $sampled = $sampler->sample($onlyCore, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), $random);
            self::assertContains($sampled->department->getName(), HospitalProfileResolver::CORE_DEPARTMENTS);
        }
    }

    private function loadReferenceCatalog(): PatternSampler
    {
        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');
        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['reference']), true);
        /** @var PatternSampler $sampler */
        $sampler = $container->get(PatternSampler::class);

        return $sampler;
    }

    public function testTheSameSeedGivesTheSameRows(): void
    {
        self::bootKernel();
        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');
        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['reference']), true);
        /** @var PatternSampler $sampler */
        $sampler = $container->get(PatternSampler::class);

        $rows = static function (int $seed) use ($sampler): array {
            $random = new RandomStreams()->stream($seed, 'patterns', '2026-01', RandomStreams::ALLOCATIONS);
            $pattern = new DefaultDistributionPatterns()->get('urban-full');
            $rows = [];
            for ($i = 0; $i < 50; ++$i) {
                $sampled = $sampler->sample($pattern, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-31 23:59:59'), $random);
                $rows[] = [
                    $sampled->createdAt->format(\DateTimeInterface::ATOM),
                    $sampled->arrivalAt->format(\DateTimeInterface::ATOM),
                    $sampled->age,
                    $sampled->gender->value,
                    $sampled->urgency->value,
                    $sampled->department->getId(),
                    $sampled->indicationRaw->getId(),
                    $sampled->isCpr,
                ];
            }

            return $rows;
        };

        $first = $rows(740);

        self::assertSame($first, $rows(740), 'same seed, same allocations');
        self::assertNotSame($first, $rows(741));
        foreach ($first as [$createdAt]) {
            self::assertGreaterThanOrEqual('2026-01-01', $createdAt);
            self::assertLessThan('2026-04-01', $createdAt, 'every day lies in the period');
        }
    }

    public function testThePatternsAreNotNearUniform(): void
    {
        $patterns = new DefaultDistributionPatterns();

        foreach (DefaultDistributionPatterns::names() as $name) {
            $pattern = $patterns->get($name);
            self::assertLessThan(0.05, $pattern->flagProbabilities()['is_cpr']);
            self::assertLessThan(0.05, $pattern->flagProbabilities()['is_pregnant']);
            foreach (['assignment', 'occasion', 'indication_normalized', 'hour_of_day'] as $key) {
                $shares = $pattern->categoricalDistribution($key);
                self::assertNotSame([], $shares, $name.' '.$key);
                self::assertEqualsWithDelta(1.0, array_sum($shares), 0.02, $name.' '.$key);
            }
        }

        $codes = array_column(PzcCodes::all(), 'code');
        foreach (DefaultDistributionPatterns::names() as $name) {
            foreach (array_keys($patterns->get($name)->categoricalDistribution('indication_normalized')) as $code) {
                self::assertContains(str_pad((string) $code, 3, '0', \STR_PAD_LEFT), $codes, sprintf('%s: "%s" must be a PZC code', $name, $code));
            }
        }
        $obstetric = static fn (string $name): float => array_sum(array_filter(
            $patterns->get($name)->categoricalDistribution('indication_normalized'),
            static fn (int|string $code): bool => \in_array(substr((string) $code, 0, 2), ['52', '53', '54'], true),
            \ARRAY_FILTER_USE_KEY,
        ));
        self::assertGreaterThan(0.0, $obstetric('urban-basic'), 'basic care gets a few obstetric cases');
        self::assertGreaterThan($obstetric('urban-basic'), $obstetric('urban-full'), 'full care gets more of them');

        $this->expectException(\InvalidArgumentException::class);
        $patterns->get('unknown');
    }
}
