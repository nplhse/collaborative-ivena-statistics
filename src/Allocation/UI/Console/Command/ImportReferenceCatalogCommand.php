<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportApplier;
use App\Allocation\Application\ReferenceCatalog\Import\ReferenceCatalogImportPlanner;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageReader;
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
    description: 'Import a reference catalog package (JSON): add missing rows, never change existing ones (ADR 020).',
)]
final readonly class ImportReferenceCatalogCommand
{
    public function __construct(
        private ReferenceCatalogPackageReader $reader,
        private ReferenceCatalogImportPlanner $planner,
        private ReferenceCatalogImportApplier $applier,
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
        $io->note('Additive import: it inserts missing rows and never changes or deletes existing ones. It is not a restore.');

        $result = $this->reader->read($source);
        if (!$result->isValid()) {
            $io->error(sprintf('%s is not a valid reference catalog package (%d problem(s)). Nothing was imported.', $source, \count($result->violations)));
            ReferenceCatalogViolationTable::render($io, $result->violations);

            return Command::FAILURE;
        }

        $plan = $this->planner->plan($result->package);
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

        if ($input->dryRun) {
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

    private function resolveUser(string $identifier): ?User
    {
        $identifier = '' === trim($identifier) ? 'admin' : trim($identifier);

        return $this->userRepository->findOneBy(['username' => $identifier])
            ?? $this->userRepository->findOneBy(['email' => mb_strtolower($identifier)]);
    }
}
