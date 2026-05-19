<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:statistics:refresh-mviews',
    description: 'Refresh statistics materialized views (all groups by default).',
)]
final class RefreshMaterializedViewsCommand extends Command
{
    public function __construct(
        private readonly MaterializedViewRefresher $materializedViewRefresher,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'overview',
            null,
            InputOption::VALUE_NONE,
            'Refresh only overview materialized views (state/dispatch hospital counts and hospital dimensions)',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $groups = $input->getOption('overview')
            ? [StatisticsMaterializedViewGroups::OVERVIEW]
            : [];

        $label = [] === $groups
            ? 'all statistics materialized views'
            : sprintf('materialized views for group(s): %s', implode(', ', $groups));

        $io->title(sprintf('Refreshing %s', $label));

        $failed = false;
        foreach ($this->materializedViewRefresher->refresh($groups) as $result) {
            $io->section($result['view']);
            if ($result['success']) {
                $io->success($result['message']);
            } else {
                $failed = true;
                $io->error($result['message']);
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
