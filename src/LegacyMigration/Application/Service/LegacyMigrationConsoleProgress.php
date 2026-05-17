<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use App\LegacyMigration\Application\Contract\LegacyMigrationProgressInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class LegacyMigrationConsoleProgress implements LegacyMigrationProgressInterface
{
    public function __construct(
        private SymfonyStyle $io,
        private LegacyMigrationProgressReporter $reporter,
    ) {
    }

    #[\Override]
    public function startPhase(string $name, int $max): void
    {
        $this->io->section($name);
        $this->reporter->startPhase($this->io, $max);
    }

    #[\Override]
    public function advance(int $step = 1): void
    {
        $this->reporter->advance($step);
    }

    #[\Override]
    public function setMessage(string $message): void
    {
        $this->reporter->setMessage($message);
    }

    #[\Override]
    public function finishPhase(): void
    {
        $this->reporter->finish();
        $this->io->newLine(2);
    }

    #[\Override]
    public function note(string $message): void
    {
        $this->reporter->clear($this->io);
        $this->io->writeln(' <comment>'.$message.'</comment>');
    }
}
