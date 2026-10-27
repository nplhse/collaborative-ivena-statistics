<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\DataFixtures\Pattern\Application\DefaultDistributionPatterns;
use App\DataFixtures\Pattern\Application\PatternSampler;
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

        foreach (DefaultDistributionPatterns::names() as $name) {
            $urgent = 0;
            for ($i = 0; $i < 200; ++$i) {
                $sampled = $sampler->sample($patterns->get($name), $start, $end);
                self::assertNotNull($sampled->indicationNormalized, $name);
                self::assertSame($sampled->indicationNormalized->getId(), $sampled->indicationRaw->getTarget()?->getId(), 'the raw label maps to the sampled indication');
                self::assertGreaterThan($sampled->createdAt, $sampled->arrivalAt);
                $urgent += AllocationUrgency::EMERGENCY === $sampled->urgency ? 1 : 0;
            }

            self::assertLessThan(100, $urgent, $name.': urgency is not uniform, most cases are not emergencies');
        }
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
            mt_srand($seed);
            $pattern = new DefaultDistributionPatterns()->get('urban-full');
            $rows = [];
            for ($i = 0; $i < 50; ++$i) {
                $sampled = $sampler->sample($pattern, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-31 23:59:59'));
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
            self::assertLessThan(0.05, $pattern->categoricalDistribution('gender')['X']);
            self::assertLessThan(0.05, $pattern->flagProbabilities()['is_cpr']);
            self::assertLessThan(0.05, $pattern->flagProbabilities()['is_pregnant']);
            foreach (['urgency', 'department', 'speciality', 'assignment', 'occasion', 'indication_normalized', 'age_bucket', 'hour_of_day'] as $key) {
                $shares = $pattern->categoricalDistribution($key);
                self::assertNotSame([], $shares, $name.' '.$key);
                self::assertEqualsWithDelta(1.0, array_sum($shares), 0.02, $name.' '.$key);
            }
        }

        $names = array_column(\App\Allocation\Infrastructure\Faker\Provider\PzcCodes::all(), 'name');
        $counts = array_count_values($names);
        foreach (array_keys($patterns->get('urban-full')->categoricalDistribution('indication_normalized')) as $indication) {
            self::assertSame(1, $counts[$indication] ?? 0, sprintf('"%s" must name exactly one PZC code', $indication));
        }

        $this->expectException(\InvalidArgumentException::class);
        $patterns->get('unknown');
    }
}
