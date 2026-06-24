<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:statistics:refresh-mviews',
    description: 'Refresh statistics materialized views (all groups by default).',
)]
final readonly class RefreshMaterializedViewsCommand
{
    public function __construct(
        private MaterializedViewRefresher $materializedViewRefresher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Refresh only overview materialized views (state/dispatch hospital counts and hospital dimensions)')]
        bool $overview = false,
    ): int {
        $groups = $overview
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
