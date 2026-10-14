<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
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

    /**
     * @param list<string> $hospital
     */
    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Option(description: 'Rebuild only these hospital ids (repeatable)')]
        array $hospital = [],
    ): int {
        $hospitalIds = [] === $hospital ? null : array_values(array_unique(array_map(intval(...), $hospital)));
        $io->title('Rebuilding closure volume projection');
        $startedAt = microtime(true);
        $progress = new ProgressBar($output);
        $progress->setFormat(' %current%/%max% events [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $currentPass = 0;

        try {
            $hospitals = $this->rebuilder->rebuild(function (int $done, int $total, int $pass) use ($progress, $output, $io, &$currentPass): void {
                if ($pass !== $currentPass) {
                    if (0 !== $currentPass) {
                        $progress->finish();
                        $output->writeln('');
                        $io->note(sprintf('Pass %d: rebuild requests arrived during pass %d, rebuilding %d events again.', $pass, $currentPass, $total));
                    }
                    $progress->start(max(1, $total));
                    $currentPass = $pass;
                }
                $progress->setProgress(min($done, max(1, $total)));
            }, $hospitalIds);
        } catch (\Throwable $exception) {
            $progress->finish();
            $output->writeln('');
            $io->error(sprintf('Closure volume projection rebuild failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $progress->finish();
        $output->writeln('');
        $io->success(sprintf(
            'Closure volume projection rebuilt for %d hospitals (%.2fs, partition peak %d bytes, process peak %.1f MB).',
            $hospitals,
            microtime(true) - $startedAt,
            $this->rebuilder->maxPartitionBytes(),
            memory_get_peak_usage(true) / 1048576,
        ));

        return Command::SUCCESS;
    }
}
