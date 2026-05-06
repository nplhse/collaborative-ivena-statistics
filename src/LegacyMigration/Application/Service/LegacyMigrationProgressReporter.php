<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @psalm-suppress UnusedClass */
final class LegacyMigrationProgressReporter
{
    private ?ProgressBar $progressBar = null;
    private int $startedAt = 0;

    public function startPhase(SymfonyStyle $io, string $name, ?int $max, bool $enabled): void
    {
        $io->section($name);
        $this->startedAt = time();
        $this->finish();
        if (!$enabled) {
            return;
        }

        $this->progressBar = new ProgressBar($io, $max ?? 0);
        if (null === $max) {
            $this->progressBar->setFormat('%current% [%bar%] %elapsed:6s%');
        } else {
            $this->progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
        }
        $this->progressBar->start();
    }

    public function advance(int $step = 1): void
    {
        $this->progressBar?->advance($step);
    }

    public function writeBatch(SymfonyStyle $io, int $legacyImportId, int $batchSize, ?int $lastAllocationId, int $migratedCount): void
    {
        $elapsedMinutes = max((time() - $this->startedAt) / 60, 0.1);
        $speed = (int) round((float) $migratedCount / (float) $elapsedMinutes);
        $io->writeln(sprintf(
            'Import %d | Batch %d | Last legacy allocation %s | Migrated %d | %d/min',
            $legacyImportId,
            $batchSize,
            null === $lastAllocationId ? '-' : (string) $lastAllocationId,
            $migratedCount,
            $speed
        ));
    }

    public function finish(): void
    {
        if ($this->progressBar instanceof ProgressBar) {
            $this->progressBar->finish();
            $this->progressBar = null;
        }
    }
}
