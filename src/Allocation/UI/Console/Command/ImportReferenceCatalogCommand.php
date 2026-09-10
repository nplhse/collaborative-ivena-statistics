<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\ReferenceCatalog\InvalidReferenceCatalogTypeException;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogImportMode;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogReader;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogReplaceBlockedException;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogSynchronizer;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\UI\Console\Input\ImportReferenceCatalogInput;
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
    description: 'Import allocation reference catalog YAML (add missing rows, or replace catalog tables on an empty install).',
)]
final readonly class ImportReferenceCatalogCommand
{
    public function __construct(
        private ReferenceCatalogReader $reader,
        private ReferenceCatalogSynchronizer $synchronizer,
        private UserRepository $userRepository,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] ImportReferenceCatalogInput $input,
    ): int {
        try {
            $types = ReferenceCatalogType::parseList($input->types);
        } catch (InvalidReferenceCatalogTypeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $source = $this->resolvePath($input->source);
        $user = $this->resolveUser($input->user);
        if (!$user instanceof User) {
            $io->error(sprintf('No user found for "%s". Create a user first or pass --user=.', $input->user));

            return Command::FAILURE;
        }

        $io->title('Import reference catalog');
        $io->writeln(sprintf(
            'Source: %s | Mode: %s%s | Types: %s',
            $source,
            $input->mode->value,
            $input->dryRun ? ' (dry run)' : '',
            implode(', ', array_map(static fn (ReferenceCatalogType $type): string => $type->value, $types)),
        ));

        try {
            $document = $this->reader->load($source);
            $result = $this->synchronizer->sync(
                $document,
                $user,
                $types,
                replace: ReferenceCatalogImportMode::Replace === $input->mode,
                updateIndicationGroups: $input->update,
                dryRun: $input->dryRun,
            );
        } catch (ReferenceCatalogReplaceBlockedException|\InvalidArgumentException|\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Type', 'Created', 'Skipped', 'Updated'], $result->tableRows());

        if ([] !== $result->warnings) {
            $io->warning('Warnings:');
            $io->listing($result->warnings);
        }

        if ($input->dryRun) {
            $io->success('Dry run finished. Re-run without --dry-run to apply changes.');

            return Command::SUCCESS;
        }

        $io->success($result->hasChanges()
            ? 'Reference catalog imported.'
            : 'Nothing to do — selected catalog rows already exist.');

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return Path::isAbsolute($path) ? $path : Path::join($this->projectDir, $path);
    }

    private function resolveUser(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ('' === $identifier) {
            $identifier = 'admin';
        }

        return $this->userRepository->findOneBy(['username' => $identifier])
            ?? $this->userRepository->findOneBy(['email' => mb_strtolower($identifier)]);
    }
}
