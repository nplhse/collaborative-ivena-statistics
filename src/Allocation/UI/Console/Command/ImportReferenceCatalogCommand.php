<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportApplier;
use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportPlanner;
use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\CatalogEntry;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogImportMode;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Application\ReferenceCatalog\Restore\CatalogMaintenanceGuard;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogDependencyInspector;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogRestoreBlockedException;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogRestorer;
use App\Allocation\Application\ReferenceCatalog\Restore\RestoreBlocker;
use App\Allocation\UI\Console\Input\ImportReferenceCatalogInput;
use App\Allocation\UI\Console\ReferenceCatalogViolationTable;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

#[AsCommand(
    name: 'app:reference:import',
    description: 'Import a reference catalog package (JSON): add missing rows, or restore the whole catalog (ADR 020).',
)]
final readonly class ImportReferenceCatalogCommand
{
    public function __construct(
        private ReferenceCatalogPackageReader $reader,
        private ReferenceCatalogImportPlanner $planner,
        private ReferenceCatalogImportApplier $applier,
        private ReferenceCatalogRestorer $restorer,
        private ReferenceCatalogDependencyInspector $inspector,
        private CatalogMaintenanceGuard $guard,
        private UserRepository $userRepository,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] ImportReferenceCatalogInput $input,
    ): int {
        $source = Path::isAbsolute($input->source) ? $input->source : Path::join($this->projectDir, $input->source);
        $user = $this->resolveUser($input->user);
        if (!$user instanceof User) {
            $io->error(sprintf('No user found for "%s". Create a user first or pass --user=.', $input->user));

            return Command::FAILURE;
        }

        $io->title('Import reference catalog');
        $io->writeln(sprintf('Source: %s | Mode: %s%s', $source, $input->mode->value, $input->dryRun ? ' (dry run)' : ''));

        $result = $this->reader->read($source);
        if (!$result->isValid()) {
            $io->error(sprintf('%s is not a valid reference catalog package (%d problem(s)). Nothing was imported.', $source, \count($result->violations)));
            ReferenceCatalogViolationTable::render($io, $result->violations);

            return Command::FAILURE;
        }

        return ReferenceCatalogImportMode::Replace === $input->mode
            ? $this->replace($io, $result->package, $user, $input->dryRun)
            : $this->add($io, $result->package, $user, $input->dryRun);
    }

    private function add(SymfonyStyle $io, ReferenceCatalogPackage $package, User $user, bool $dryRun): int
    {
        $io->note('Additive import: it inserts missing rows and never changes or deletes existing ones. It is not a restore.');

        $plan = $this->planner->plan($package);
        $io->table(['Type', 'Create', 'Skip'], array_map(
            static fn (string $type, array $count): array => [$type, (string) $count['create'], (string) $count['skip']],
            array_keys($plan->counts),
            array_values($plan->counts),
        ));

        if ($plan->hasConflicts()) {
            $io->error(sprintf('The package conflicts with the database (%d conflict(s)). Nothing was imported.', \count($plan->conflicts)));
            ReferenceCatalogViolationTable::render($io, $plan->conflicts);

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run: %d row(s) would be created. Run again without --dry-run to import.', $plan->createCount()));

            return Command::SUCCESS;
        }

        if (0 === $plan->createCount()) {
            $io->success('Nothing to do: every row of the package already exists.');

            return Command::SUCCESS;
        }

        $this->applier->apply($plan, $user);
        $io->success(sprintf('Imported %d row(s). The reference data cache was cleared.', $plan->createCount()));

        return Command::SUCCESS;
    }

    private function replace(SymfonyStyle $io, ReferenceCatalogPackage $package, User $user, bool $dryRun): int
    {
        $io->note('Restore: deletes the whole catalog and inserts the package with its ids. It never deletes operational data or permissions and refuses while they exist.');

        if (!$package->meta->complete) {
            $io->error('A restore needs a complete package (meta.complete = true). Export without --types.');

            return Command::FAILURE;
        }

        $lock = $this->guard->acquire();
        if (!$lock instanceof \Symfony\Component\Lock\LockInterface) {
            $io->error('Another restore or development reset is running. Try again when it has finished.');

            return Command::FAILURE;
        }

        try {
            $busy = $this->guard->busyQueues();
            if ([] !== $busy) {
                $io->error('Messages are waiting or being handled. Stop the workers and wait until the queues are empty.');
                $io->table(['Queue', 'Messages'], array_map(
                    static fn (string $queue, int $count): array => [$queue, (string) $count],
                    array_keys($busy),
                    array_values($busy),
                ));

                return Command::FAILURE;
            }

            $current = $this->restorer->currentCounts();
            $incoming = $this->packageCounts($package);
            $io->table(['Type', 'Delete', 'Create'], array_map(
                static fn (string $type): array => [$type, (string) ($current[$type] ?? 0), (string) ($incoming[$type] ?? 0)],
                array_keys($incoming),
            ));

            $blockers = $this->inspector->blockers();
            if ([] !== $blockers) {
                $this->renderBlockers($io, $blockers);

                return Command::FAILURE;
            }

            if ($dryRun) {
                $io->success('Dry run: no blockers. Run again without --dry-run to restore.');

                return Command::SUCCESS;
            }

            try {
                $this->restorer->restore($package, $user);
            } catch (ReferenceCatalogRestoreBlockedException $exception) {
                $this->renderBlockers($io, $exception->blockers);

                return Command::FAILURE;
            }

            $io->success(sprintf('Restored the catalog: %d row(s) created. Materialized views were refreshed and the reference data cache was cleared.', array_sum($incoming)));

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, int>
     */
    private function packageCounts(ReferenceCatalogPackage $package): array
    {
        $counts = [];
        foreach (ReferenceCatalogType::importOrder() as $type) {
            $entries = $package->entriesOf($type);
            $counts[$type->value] = \count($entries);
            if (ReferenceCatalogType::Department === $type || ReferenceCatalogType::Speciality === $type) {
                $counts[$type->value.'-alias'] = array_sum(array_map(
                    static fn (CatalogEntry $entry): int => $entry instanceof AliasedEntry ? \count($entry->aliases) : 0,
                    $entries,
                ));
            }
        }

        return $counts;
    }

    /**
     * @param list<RestoreBlocker> $blockers
     */
    private function renderBlockers(SymfonyStyle $io, array $blockers): void
    {
        $io->error(sprintf('Restore blocked: %d table(s) refer to the catalog. Nothing was changed. A restore is meant for an installation without operational data; use the development reset locally, or --mode=add.', \count($blockers)));
        $io->table(['Table', 'Kind', 'Rows'], array_map(
            static fn (RestoreBlocker $blocker): array => [$blocker->table, $blocker->kind, (string) $blocker->rows],
            $blockers,
        ));
    }

    private function resolveUser(string $identifier): ?User
    {
        $identifier = '' === trim($identifier) ? 'admin' : trim($identifier);

        return $this->userRepository->findOneBy(['username' => $identifier])
            ?? $this->userRepository->findOneBy(['email' => mb_strtolower($identifier)]);
    }
}
