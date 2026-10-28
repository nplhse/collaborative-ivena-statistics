<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\UI\Console;

use App\DataFixtures\Pattern\Application\DefaultPatternPackage;
use App\DataFixtures\Pattern\Package\PatternPackageReader;
use App\DataFixtures\Pattern\Package\PatternPackageWriter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'app:patterns:write-defaults',
    description: 'Development only: write the code defaults of the allocation patterns as a pattern package (ADR 022).',
)]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class WriteDefaultPatternPackageCommand
{
    public function __construct(
        private PatternPackageWriter $writer,
        private PatternPackageReader $reader,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Target file: a path, or a file name in var/patterns/')]
        string $output = 'defaults.json',
        #[Option(description: 'Replace an existing file')]
        bool $force = false,
    ): int {
        $path = PatternPackagePath::resolve($output, $this->projectDir);
        if (is_file($path) && !$force) {
            $io->error(sprintf('%s exists. Pass --force to replace it.', $path));

            return Command::FAILURE;
        }

        $package = $this->writer->seal(DefaultPatternPackage::build(\DateTimeImmutable::createFromInterface($this->clock->now())));
        $this->writer->write($path, $package);

        $result = $this->reader->read($path);
        if (!$result->isValid()) {
            $io->error(sprintf('The written package %s does not pass validation.', $path));
            PatternViolationTable::render($io, $result->violations);

            return Command::FAILURE;
        }

        $io->success(sprintf('Wrote the code defaults to %s (checksum %s).', $path, $package->meta->checksum->value));

        return Command::SUCCESS;
    }
}
