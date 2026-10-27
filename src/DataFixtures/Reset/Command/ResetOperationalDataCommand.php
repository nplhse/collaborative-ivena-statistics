<?php

declare(strict_types=1);

namespace App\DataFixtures\Reset\Command;

use App\Allocation\Application\ReferenceCatalog\Restore\CatalogMaintenanceGuard;
use App\DataFixtures\Reset\OperationalDataReset;
use App\DataFixtures\Reset\ResetStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:dev:reset-operational-data',
    description: 'Development only: delete data that depends on the reference catalog, so a restore can run (ADR 020).',
)]
final readonly class ResetOperationalDataCommand
{
    /** Environments where the reset may run; the demo environment of #791 joins this list. */
    private const array ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private OperationalDataReset $reset,
        private CatalogMaintenanceGuard $guard,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Show what would be deleted without deleting it', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Do not ask for confirmation')]
        bool $force = false,
    ): int {
        if (!\in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $io->error(sprintf('The reset deletes operational data and only runs in %s, not in "%s".', implode(' and ', self::ALLOWED_ENVIRONMENTS), $this->environment));

            return Command::FAILURE;
        }

        $io->title('Reset operational data');
        $io->note('Deletes allocations, MCI cases, imports, closures, projections, access grants, hospital owners, hospital job runs and notifications and saved user views. Users and the reference catalog stay.');

        $lock = $this->guard->acquire();
        if (!$lock instanceof \Symfony\Component\Lock\LockInterface) {
            $io->error('Another restore or development reset is running. Try again when it has finished.');

            return Command::FAILURE;
        }

        try {
            $busy = $this->guard->busyQueues();
            if ([] !== $busy) {
                $io->error('Messages are waiting or being handled. Stop the workers and wait until the queues are empty.');
                $io->table(['Queue', 'Messages'], array_map(
                    static fn (string $queue, int $count): array => [$queue, (string) $count],
                    array_keys($busy),
                    array_values($busy),
                ));

                return Command::FAILURE;
            }

            if ($dryRun) {
                $this->renderSteps($io, $this->reset->preview());
                $io->success('Dry run: nothing was deleted.');

                return Command::SUCCESS;
            }

            if (!$force && !$io->confirm('Delete this data now?', false)) {
                $io->warning('Nothing was deleted.');

                return Command::FAILURE;
            }

            $this->renderSteps($io, $this->reset->run());
            $io->success('Operational data was reset and the materialized views were refreshed. A restore can run now.');

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<ResetStep> $steps
     */
    private function renderSteps(SymfonyStyle $io, array $steps): void
    {
        $io->table(['Table', 'Kind', 'Action', 'Rows'], array_map(
            static fn (ResetStep $step): array => [$step->table, $step->kind, $step->action, (string) $step->rows],
            $steps,
        ));
    }
}
