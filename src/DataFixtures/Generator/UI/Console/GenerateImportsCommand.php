<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Catalog\CatalogMismatchException;
use App\DataFixtures\Generator\Application\GenerationPreflight;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
use App\DataFixtures\Generator\Application\Run\GenerationRunRequest;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\Run\GenerationRunTracker;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:generate:imports',
    description: 'Development only: add a synthetic allocation import for one month to existing hospitals (ADR 022).',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class GenerateImportsCommand
{
    /** Environments where synthetic data may be written; the demo environment of #791 joins this list. */
    private const array ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private FixtureVolumeResolver $volumeResolver,
        private HospitalMonthGenerator $generator,
        private EntityManagerInterface $entityManager,
        private SyntheticDataPipeline $pipeline,
        private ClockInterface $clock,
        private GenerationPreflight $preflight,
        private GenerationRunTracker $runs,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @param list<string> $hospital
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Public id of a hospital (repeatable)')]
        array $hospital = [],
        #[Option(description: 'Month to generate (YYYY-MM)')]
        string $month = '',
        #[Option(description: 'Seed instead of the configured one')]
        ?int $seed = null,
        #[Option(description: 'Pattern source instead of FIXTURES_PATTERNS: "defaults" or a package (file name in var/patterns/ or a path)')]
        string $patterns = '',
        #[Option(description: 'Show the planned counts without writing anything', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Track a generation run and leave the projections to a Messenger worker instead of waiting for them')]
        bool $async = false,
        #[Option(description: 'With --async: notify the owner of each hospital in-app once its data is fully processed')]
        bool $notify = false,
    ): int {
        if (!\in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $io->error(sprintf('Synthetic data is only generated in %s, not in "%s".', implode(' and ', self::ALLOWED_ENVIRONMENTS), $this->environment));

            return Command::FAILURE;
        }
        if ($notify && !$async) {
            $io->error('--notify needs --async: notifications follow the tracked background processing.');

            return Command::INVALID;
        }
        if ([] === $hospital || '' === $month) {
            $io->error('Pass at least one --hospital=<public id> and --month=YYYY-MM.');

            return Command::INVALID;
        }

        $volume = $this->volumeResolver->resolve();
        if (null !== $seed) {
            $volume = $volume->withSeed($seed);
        }
        if ('' !== $patterns) {
            $volume = $volume->withPatterns($patterns);
        }

        try {
            $this->preflight->check($volume);
        } catch (InvalidPatternPackageException|CatalogMismatchException|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $lastDay = $volume->lastDay($this->clock);

        $rows = [];
        $failed = false;
        $allocationImports = [];
        $hospitalIds = [];
        /** @var array<string, Hospital|null> $entities */
        $entities = [];
        foreach ($hospital as $publicId) {
            $entities[$publicId] = $this->findHospital($publicId);
        }
        $runId = $async && !$dryRun ? $this->startRun($entities, $volume->seed, $volume->patterns, $month, $notify) : null;

        foreach ($entities as $publicId => $entity) {
            if (!$entity instanceof Hospital) {
                $rows[] = [$publicId, '–', $month, '–', 'unknown hospital'];
                $failed = true;
                continue;
            }
            $entityId = (int) $entity->getId();

            $profile = $this->generator->profile($volume, $entity);
            $structure = sprintf('%s, %.1f/day, %d %% closed', $profile->configuration, $profile->dailyRate($volume), (int) round($profile->closureShare * 100.0));
            try {
                if ($dryRun) {
                    $rows[] = [(string) $entity->getName(), $structure, $month, (string) $this->generator->plan($volume, $entity, $month, $lastDay)->allocationCount(), 'planned'];
                    continue;
                }

                if (null !== $runId) {
                    $this->runs->hospitalStarted($runId, $entityId);
                }
                $generated = $this->generator->generate($volume, $entity, $month, $lastDay);
                if (null !== $runId) {
                    $this->runs->monthGenerated($runId, $entityId, $generated);
                }
                $allocationImports[] = $generated->importId;
                $hospitalIds[] = $entityId;
                $rows[] = [(string) $entity->getName(), $structure, $month, (string) $generated->allocations, sprintf('import %d, %d MCI cases; closure import %d, %d closures', $generated->importId, $generated->mciCases, $generated->closureImportId, $generated->closures)];
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $rows[] = [(string) $entity->getName(), $structure, $month, '–', $exception->getMessage()];
                $failed = true;
                if (null !== $runId) {
                    $this->runs->hospitalFailed($runId, $entityId, 'generation_failed', $exception->getMessage());
                }
            } finally {
                $this->entityManager->clear();
            }
        }

        $handedOver = [];
        if (null !== $runId) {
            $handedOver = $this->runs->generationFinished($runId);
        } elseif ([] !== $allocationImports && $volume->rebuildProjection) {
            $this->pipeline->afterImports($allocationImports, $hospitalIds);
        }

        $io->table(['Hospital', 'Profile', 'Month', 'Allocations', 'Result'], $rows);
        if (null !== $runId) {
            $io->note(sprintf('Generation run %s: projections of %d hospital(s) continue in the Messenger worker. Follow them with: php bin/console app:generate:run:show %s', $runId, \count($handedOver), $runId));
        }
        if ($failed) {
            $io->error('Some months were not generated.');

            return Command::FAILURE;
        }
        $io->success($dryRun ? 'Dry run: nothing was written.' : sprintf('Generated with seed %d.', $volume->seed));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, Hospital|null> $entities
     */
    private function startRun(array $entities, int $seed, string $patterns, string $month, bool $notify): ?string
    {
        $ids = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Hospital) {
                $ids[] = (int) $entity->getId();
            }
        }
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return null;
        }

        return $this->runs->start(new GenerationRunRequest(
            scope: 1 === \count($ids) ? GenerationRunScope::One : GenerationRunScope::Selected,
            hospitalIds: $ids,
            seed: $seed,
            fromMonth: $month,
            toMonth: $month,
            settings: ['command' => 'app:generate:imports', 'patterns' => $patterns],
            notify: $notify,
        ));
    }

    private function findHospital(string $publicId): ?Hospital
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }

        return $this->entityManager->getRepository(Hospital::class)->findOneBy(['publicId' => Uuid::fromString($publicId)]);
    }
}
