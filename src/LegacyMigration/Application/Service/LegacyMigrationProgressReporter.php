<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Service;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

final class LegacyMigrationProgressReporter
{
    private ?ProgressBar $progressBar = null;

    public function startPhase(OutputInterface $output, int $max): void
    {
        $this->finish();
        $this->progressBar = new ProgressBar($output, max(0, $max));
        if (0 === $max) {
            $this->progressBar->setFormat('%current% [%bar%] %elapsed:6s% %message%');
        } else {
            $this->progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s% | %message%');
        }
        $this->progressBar->setMessage('');
        $this->progressBar->start();
    }

    public function advance(int $step = 1): void
    {
        $this->progressBar?->advance($step);
    }

    public function setMessage(string $message): void
    {
        $this->progressBar?->setMessage($message);
        $this->progressBar?->display();
    }

    public function finish(): void
    {
        if ($this->progressBar instanceof ProgressBar) {
            $this->progressBar->finish();
            $this->progressBar = null;
        }
    }

    public function clear(OutputInterface $output): void
    {
        if ($this->progressBar instanceof ProgressBar) {
            $this->progressBar->clear();
        } else {
            $output->write("\r\033[K");
        }
    }
}
