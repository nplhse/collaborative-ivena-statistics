<?php

declare(strict_types=1);

namespace App\Encounter\UI\Console\Command;

use App\Encounter\Application\Catalog\MtsCatalogFirstSeenBackfill;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time backfill of provisional MTS catalog entries from the codes already stored in `encounter` (ADR 032,
 * #1157). Safe to run again: codes that resolve create nothing. Prints codes only, never encounter data.
 */
#[AsCommand(
    name: 'app:encounter:mts-catalog:backfill-first-seen',
    description: 'Create provisional MTS catalog entries for stored encounter codes that resolve to nothing (ADR 032).',
)]
final readonly class BackfillMtsCatalogFirstSeenCommand
{
    public function __construct(
        private MtsCatalogFirstSeenBackfill $backfill,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $created = $this->backfill->run();

        $rows = [];
        foreach ($created as $kind => $codes) {
            foreach ($codes as $code) {
                $rows[] = [$kind, $code];
            }
        }

        if ([] === $rows) {
            $io->success('Every stored MTS code resolves; no entry was created.');

            return Command::SUCCESS;
        }

        $io->table(['Kind', 'Code'], $rows);
        $io->success(\sprintf('%d provisional MTS catalog entr%s created.', \count($rows), 1 === \count($rows) ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
