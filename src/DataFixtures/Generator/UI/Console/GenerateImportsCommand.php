<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolumeResolver;
use App\DataFixtures\Generator\Application\Catalog\CatalogCompatibility;
use App\DataFixtures\Generator\Application\Catalog\CatalogMismatchException;
use App\DataFixtures\Generator\Application\HospitalMonthGenerator;
use App\DataFixtures\Generator\Application\HospitalProfileResolver;
use App\DataFixtures\Generator\Application\SyntheticDataPipeline;
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
        private HospitalProfileResolver $profiles,
        private EntityManagerInterface $entityManager,
        private SyntheticDataPipeline $pipeline,
        private ClockInterface $clock,
        private CatalogCompatibility $catalog,
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
        #[Option(description: 'Show the planned counts without writing anything', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        if (!\in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $io->error(sprintf('Synthetic data is only generated in %s, not in "%s".', implode(' and ', self::ALLOWED_ENVIRONMENTS), $this->environment));

            return Command::FAILURE;
        }
        if ([] === $hospital || '' === $month) {
            $io->error('Pass at least one --hospital=<public id> and --month=YYYY-MM.');

            return Command::INVALID;
        }

        try {
            $this->catalog->assertCompatible();
        } catch (CatalogMismatchException|\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $volume = $this->volumeResolver->resolve();
        if (null !== $seed) {
            $volume = $volume->withSeed($seed);
        }
        $lastDay = $volume->lastDay($this->clock);

        $rows = [];
        $failed = false;
        $allocationImports = [];
        $hospitalIds = [];
        foreach ($hospital as $publicId) {
            $entity = $this->findHospital($publicId);
            if (!$entity instanceof Hospital) {
                $rows[] = [$publicId, '–', $month, '–', 'unknown hospital'];
                $failed = true;
                continue;
            }

            $structure = $this->profiles->resolve($entity, $volume->seed)->configuration;
            try {
                if ($dryRun) {
                    $rows[] = [(string) $entity->getName(), $structure, $month, (string) $this->generator->plan($volume, $entity, $month, $lastDay)->allocationCount(), 'planned'];
                    continue;
                }

                $generated = $this->generator->generate($volume, $entity, $month, $lastDay);
                $allocationImports[] = $generated->importId;
                $hospitalIds[] = (int) $entity->getId();
                $rows[] = [(string) $entity->getName(), $structure, $month, (string) $generated->allocations, sprintf('import %d, %d MCI cases; closure import %d, %d closures', $generated->importId, $generated->mciCases, $generated->closureImportId, $generated->closures)];
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $rows[] = [(string) $entity->getName(), $structure, $month, '–', $exception->getMessage()];
                $failed = true;
            } finally {
                $this->entityManager->clear();
            }
        }

        if ([] !== $allocationImports && $volume->rebuildProjection) {
            $this->pipeline->afterImports($allocationImports, $hospitalIds);
        }

        $io->table(['Hospital', 'Structure', 'Month', 'Allocations', 'Result'], $rows);
        if ($failed) {
            $io->error('Some months were not generated.');

            return Command::FAILURE;
        }
        $io->success($dryRun ? 'Dry run: nothing was written.' : sprintf('Generated with seed %d.', $volume->seed));

        return Command::SUCCESS;
    }

    private function findHospital(string $publicId): ?Hospital
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }

        return $this->entityManager->getRepository(Hospital::class)->findOneBy(['publicId' => Uuid::fromString($publicId)]);
    }
}
