<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:statistics:rebuild-closure-volume-projection',
    description: 'Rebuild the closure volume projection from closures and allocation statistics.',
)]
final readonly class RebuildClosureVolumeProjectionCommand
{
    public function __construct(private ClosureVolumeProjectionRebuildInterface $rebuilder)
    {
    }

    public function __invoke(SymfonyStyle $io, OutputInterface $output): int
    {
        $io->title('Rebuilding closure volume projection');
        $startedAt = microtime(true);
        $progress = new ProgressBar($output);
        $progress->setFormat(' %current%/%max% events [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $started = false;

        try {
            $hospitals = $this->rebuilder->rebuild(function (int $done, int $total) use ($progress, &$started): void {
                if (!$started) {
                    $progress->setMaxSteps(max(1, $total));
                    $progress->start();
                    $started = true;
                }
                $progress->setProgress(min($done, max(1, $total)));
            });
        } catch (\Throwable $exception) {
            $progress->finish();
            $output->writeln('');
            $io->error(sprintf('Closure volume projection rebuild failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $progress->finish();
        $output->writeln('');
        $io->success(sprintf(
            'Closure volume projection rebuilt for %d hospitals (%.2fs, partition peak %d bytes).',
            $hospitals,
            microtime(true) - $startedAt,
            $this->rebuilder->maxPartitionBytes(),
        ));

        return Command::SUCCESS;
    }
}
