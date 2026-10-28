<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Dataset\DatasetHospitalPlan;
use App\DataFixtures\Generator\Application\Dataset\DatasetProgress;
use App\DataFixtures\Generator\Application\GeneratedMonth;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A progress bar over the months of a dataset run, with the hospital being generated as its message.
 */
final readonly class ConsoleDatasetProgress implements DatasetProgress
{
    private ProgressBar $bar;

    public function __construct(private SymfonyStyle $io, int $months)
    {
        $this->bar = $io->createProgressBar($months);
        $this->bar->setFormat(" %current%/%max% months [%bar%] %elapsed:6s% %message%\n");
        $this->bar->setMessage('');
        $this->bar->start();
    }

    #[\Override]
    public function hospitalStarted(DatasetHospitalPlan $hospital): void
    {
        $this->bar->setMessage($hospital->name);
        $this->bar->display();
    }

    #[\Override]
    public function monthGenerated(DatasetHospitalPlan $hospital, string $month, GeneratedMonth $generated): void
    {
        $this->bar->setMessage(\sprintf('%s %s: %d allocations, %d closures', $hospital->name, $month, $generated->allocations, $generated->closures));
        $this->bar->advance();
    }

    #[\Override]
    public function hospitalFinished(DatasetHospitalPlan $hospital): void
    {
    }

    #[\Override]
    public function hospitalFailed(DatasetHospitalPlan $hospital, string $message): void
    {
        $this->bar->clear();
        $this->io->warning(\sprintf('%s failed and was rolled back: %s', $hospital->name, $message));
        $this->bar->display();
    }

    public function finish(): void
    {
        $this->bar->finish();
        $this->io->newLine();
    }
}
