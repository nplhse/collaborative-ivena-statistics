<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Catalog\CatalogMismatchException;
use App\DataFixtures\Generator\Application\GenerationPreflight;
use App\DataFixtures\Generator\Application\Scenario\ScenarioCatalog;
use App\DataFixtures\Generator\Application\Scenario\ScenarioGenerator;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:generate:scenario',
    description: 'Development only: lay a scenario (mci, icy-road, flu-wave, heat) over existing synthetic data (ADR 022).',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class GenerateScenarioCommand
{
    /** Environments where synthetic data may be written; the demo environment of #791 joins this list. */
    private const array ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private FixtureVolumeResolver $volumeResolver,
        private ScenarioGenerator $generator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private GenerationPreflight $preflight,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @param list<string> $hospital
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Scenario name; empty lists the scenarios')]
        string $scenario = '',
        #[Option(description: 'Dispatch area whose participating hospitals get the scenario', name: 'dispatch-area')]
        string $dispatchArea = '',
        #[Option(description: 'Public id of a hospital instead of a dispatch area (repeatable)')]
        array $hospital = [],
        #[Option(description: 'First day of the scenario (YYYY-MM-DD)')]
        string $at = '',
        #[Option(description: 'Seed instead of the configured one')]
        ?int $seed = null,
        #[Option(description: 'Pattern source instead of FIXTURES_PATTERNS: "defaults" or a package (file name in var/patterns/ or a path)')]
        string $patterns = '',
        #[Option(description: 'Show the planned counts without writing anything', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Scales extra cases, MCI patients and closures (0.5–2.0)')]
        float $intensity = 1.0,
        #[Option(description: 'Delete the imports of the scenario starting on --at instead of applying it')]
        bool $remove = false,
    ): int {
        if (!\in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $io->error(sprintf('Synthetic data is only generated in %s, not in "%s".', implode(' and ', self::ALLOWED_ENVIRONMENTS), $this->environment));

            return Command::FAILURE;
        }
        if ('' === $scenario) {
            $io->table(['Scenario', 'Description'], array_map(
                static fn (\App\DataFixtures\Generator\Application\Scenario\ScenarioDefinition $definition): array => [$definition->name, $definition->description],
                array_values(ScenarioCatalog::all()),
            ));

            return Command::SUCCESS;
        }

        $startDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $at);
        if ($remove) {
            return $this->remove($io, $scenario, $startDay, $at, $seed);
        }
        if (false === $startDay || $startDay->format('Y-m-d') !== $at || ('' === $dispatchArea) === ([] === $hospital)) {
            $io->error('Pass --at=YYYY-MM-DD and either --dispatch-area=<name> or --hospital=<public id>.');

            return Command::INVALID;
        }

        try {
            $definition = ScenarioCatalog::get($scenario);
            $volume = $this->volumeResolver->resolve();
            if (null !== $seed) {
                $volume = $volume->withSeed($seed);
            }
            if ('' !== $patterns) {
                $volume = $volume->withPatterns($patterns);
            }
            $this->preflight->check($volume);
            $lastDay = $volume->lastDay($this->clock);
            $hospitals = '' !== $dispatchArea ? $this->hospitalsIn($dispatchArea) : $this->hospitalsById($hospital);
            $area = '' !== $dispatchArea ? $dispatchArea : implode(',', $hospital);

            if ($dryRun) {
                $rows = [];
                foreach ($this->generator->plan($definition, $volume, $hospitals, $startDay, $lastDay, $area, $intensity) as $plan) {
                    $rows[] = [(string) $plan->hospital->getName(), implode(' – ', array_unique([$plan->days[0], $plan->lastDay()])), (string) $plan->allocationCount(), (string) \count($plan->closures), (string) $plan->mciPatients];
                }
                $io->table(['Hospital', 'Days', 'Allocations', 'Closures', 'MCI patients'], $rows);
                $io->success('Dry run: nothing was written.');

                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($this->generator->apply($definition, $volume, $hospitals, $startDay, $lastDay, $area, $intensity) as $result) {
                $rows[] = [$result->hospital, (string) $result->importId, (string) $result->allocations, (string) $result->closures, (string) $result->mciPatients];
            }
        } catch (\DomainException|\InvalidArgumentException|InvalidPatternPackageException|CatalogMismatchException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Hospital', 'Import', 'Allocations', 'Closures', 'MCI patients'], $rows);
        $io->success(sprintf('Scenario "%s" applied with seed %d. Remove it with --remove --at=%s.', $scenario, $volume->seed, $at));

        return Command::SUCCESS;
    }

    private function remove(SymfonyStyle $io, string $scenario, \DateTimeImmutable|false $startDay, string $at, ?int $seed): int
    {
        if (false === $startDay || $startDay->format('Y-m-d') !== $at) {
            $io->error('Pass --at=YYYY-MM-DD with the first day of the scenario to remove.');

            return Command::INVALID;
        }

        try {
            $seed ??= $this->volumeResolver->resolve()->seed;
            $hospitals = $this->generator->remove(ScenarioCatalog::get($scenario), $seed, $startDay);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Scenario "%s" of %s removed with seed %d: %s. Projections, views and KPIs are up to date.', $scenario, $at, $seed, implode(', ', $hospitals)));

        return Command::SUCCESS;
    }

    /**
     * @return list<Hospital>
     */
    private function hospitalsIn(string $dispatchArea): array
    {
        /** @var list<Hospital> $hospitals */
        $hospitals = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(Hospital::class, 'h')
            ->join('h.dispatchArea', 'd')
            ->where('d.name = :area')
            ->andWhere('h.isParticipating = true')
            ->setParameter('area', $dispatchArea)
            ->orderBy('h.publicId')
            ->getQuery()
            ->getResult();

        return $hospitals;
    }

    /**
     * @param list<string> $publicIds
     *
     * @return list<Hospital>
     */
    private function hospitalsById(array $publicIds): array
    {
        $hospitals = [];
        foreach ($publicIds as $publicId) {
            $hospital = Uuid::isValid($publicId) ? $this->entityManager->getRepository(Hospital::class)->findOneBy(['publicId' => Uuid::fromString($publicId)]) : null;
            if (!$hospital instanceof Hospital) {
                throw new \InvalidArgumentException(sprintf('Unknown hospital "%s".', $publicId));
            }
            $hospitals[] = $hospital;
        }

        return $hospitals;
    }
}
