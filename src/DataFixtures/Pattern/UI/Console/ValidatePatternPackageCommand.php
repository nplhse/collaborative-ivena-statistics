<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\UI\Console;

use App\DataFixtures\Pattern\Infrastructure\Query\PatternCatalogQuery;
use App\DataFixtures\Pattern\Package\PatternPackageReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'app:patterns:validate',
    description: 'Development only: check an allocation pattern package (JSON) against the format and the reference catalog (ADR 022).',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class ValidatePatternPackageCommand
{
    public function __construct(
        private PatternPackageReader $reader,
        private PatternCatalogQuery $catalog,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Package file: a path, or a file name in var/patterns/')]
        string $file,
        #[Option(description: 'Skip the check against the reference catalog in the database', name: 'no-catalog')]
        bool $noCatalog = false,
    ): int {
        $path = PatternPackagePath::resolve($file, $this->projectDir);
        $result = $this->reader->read($path, $noCatalog ? null : $this->catalog->current());

        if (!$result->isValid()) {
            $io->error(sprintf('%s is not a valid pattern package (%d problem(s)).', $path, \count($result->violations)));
            PatternViolationTable::render($io, $result->violations);

            return Command::FAILURE;
        }

        $meta = $result->package->meta;
        $io->definitionList(
            ['Source' => $meta->source],
            ['Created at' => $meta->createdAt],
            ['Catalog' => $meta->catalog ?? '—'],
            ['Checksum' => $meta->checksum->value],
            ['Segments' => (string) ($meta->counts['segments'] ?? 0)],
            ['Indication groups' => (string) ($meta->counts['indication_groups'] ?? 0)],
            ['Indications' => (string) ($meta->counts['indications'] ?? 0)],
        );
        $io->success(sprintf('%s is a valid pattern package%s.', $path, $noCatalog ? ' (catalog not checked)' : ''));

        return Command::SUCCESS;
    }
}
