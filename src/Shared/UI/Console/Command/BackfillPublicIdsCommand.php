<?php

declare(strict_types=1);

namespace App\Shared\UI\Console\Command;

use App\Shared\Application\PublicId\PublicIdBackfillExitCode;
use App\Shared\Application\PublicId\PublicIdBackfillInterruptedException;
use App\Shared\Application\PublicId\PublicIdBackfillRunControl;
use App\Shared\Application\PublicId\PublicIdBackfillService;
use App\Shared\UI\Console\Input\BackfillPublicIdsInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:explore:backfill-public-ids',
    description: 'Backfill public_id UUID v4 values for explore detail resources. Exit codes: 0=complete, 1=more work remains, 2=critical.',
)]
final class BackfillPublicIdsCommand implements SignalableCommandInterface
{
    private ?PublicIdBackfillRunControl $runControl = null;

    public function __construct(
        private readonly PublicIdBackfillService $backfillService,
    ) {
    }

    /**
     * @return list<int>
     */
    #[\Override]
    public function getSubscribedSignals(): array
    {
        if (!\function_exists('pcntl_signal')) {
            return [];
        }

        return [\SIGINT, \SIGTERM];
    }

    #[\Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->runControl?->requestStop($signal);

        return false;
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] BackfillPublicIdsInput $input,
    ): int {
        $tables = $input->table;

        $io->title('Backfill explore public_id (UUID v4)');

        if ($input->dryRun) {
            $io->note('Dry run: no rows will be written.');
        }

        $this->runControl = new PublicIdBackfillRunControl();
        $startedAt = microtime(true);

        try {
            $result = $this->backfillService->run(
                dryRun: $input->dryRun,
                tables: $tables,
                batchSize: $input->batchSize,
                maxRuntimeSeconds: $input->maxRuntime,
                runControl: $this->runControl,
            );
        } catch (PublicIdBackfillInterruptedException $exception) {
            $io->warning(sprintf('Interrupted by signal %d.', $exception->getSignal()));

            return PublicIdBackfillExitCode::MORE_WORK;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return PublicIdBackfillExitCode::CRITICAL;
        }

        $rows = [];
        foreach (PublicIdBackfillService::TABLE_ORDER as $table) {
            if (!\array_key_exists($table, $result->remainingByTable)) {
                continue;
            }
            $rows[] = [
                $table,
                (string) ($result->updatedByTable[$table] ?? 0),
                (string) $result->remainingByTable[$table],
            ];
        }

        $io->table(['Table', $input->dryRun ? 'Would update' : 'Updated', 'Remaining'], $rows);

        if ($input->dryRun) {
            $io->success('Dry run finished. Re-run without --dry-run to apply changes.');

            return PublicIdBackfillExitCode::SUCCESS;
        }

        $elapsed = microtime(true) - $startedAt;
        if ($result->completed) {
            $io->success(sprintf('Backfill finished in %.2fs.', $elapsed));

            return PublicIdBackfillExitCode::SUCCESS;
        }

        $io->warning(sprintf(
            'Backfill paused after %.2fs with rows still missing public_id. Re-run the command or use bin/backfill-public-ids-until-done.',
            $elapsed,
        ));

        return PublicIdBackfillExitCode::MORE_WORK;
    }
}
