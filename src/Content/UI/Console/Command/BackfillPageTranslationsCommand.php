<?php

declare(strict_types=1);

namespace App\Content\UI\Console\Command;

use App\Content\Application\Page\BackfillPageTranslationsService;
use App\Content\UI\Console\Input\BackfillPageTranslationsInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:content:backfill-page-translations',
    description: 'Create default-locale PageTranslation rows from legacy Page title/slug/path/content/status columns.',
)]
final readonly class BackfillPageTranslationsCommand
{
    public function __construct(
        private BackfillPageTranslationsService $backfillPageTranslationsService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] BackfillPageTranslationsInput $input,
    ): int {
        $io->title('Backfill page translations');

        if ($input->dryRun) {
            $io->note('Dry run: no database changes will be written. Re-run without --dry-run to persist.');
        }

        $result = $this->backfillPageTranslationsService->backfill($input->dryRun);

        $io->table(
            ['Metric', 'Count'],
            [
                ['Processed pages', (string) $result->processed],
                ['Created translations', (string) $result->created],
                ['Skipped (already present)', (string) $result->skipped],
                ['Errors', (string) $result->errors],
            ],
        );

        $io->text(sprintf('Content default locale: %s', $result->locale));

        if ([] !== $result->errorMessages) {
            $io->section('Errors');
            foreach ($result->errorMessages as $message) {
                $io->writeln(' - '.$message);
            }
        }

        if ($result->errors > 0) {
            $io->warning(sprintf('Completed with %d error(s).', $result->errors));

            return Command::FAILURE;
        }

        $io->success($input->dryRun
            ? 'Dry run completed successfully.'
            : 'Backfill completed successfully.');

        return Command::SUCCESS;
    }
}
