<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Console;

use App\Statistics\AnalysisExplorer\Application\ExplorerSystemViewSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'statistics:explorer-views:sync',
    description: 'Insert or update system demo views for Analysis Explorer V2.',
)]
final class ExplorerSystemViewSyncCommand extends Command
{
    public function __construct(
        private readonly ExplorerSystemViewSeeder $seeder,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sync Analysis Explorer system views');

        $result = $this->seeder->sync();

        $io->table(
            ['Action', 'Count'],
            [
                ['Created', (string) $result->created],
                ['Updated', (string) $result->updated],
                ['Skipped', (string) $result->skipped],
            ],
        );

        $io->success($result->hasChanges()
            ? 'Explorer system views synced.'
            : 'Nothing to do — all system views are up to date.');

        return Command::SUCCESS;
    }
}
