<?php

declare(strict_types=1);

namespace App\Analytics\UI\Console\Command;

use App\Analytics\Application\Service\AnalyticsAccessBackfillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analytics:classify-accesses',
    description: 'Mark stored raw requests that were recorded before the counted-access flag existed (preview unless --apply; idempotent).',
)]
final readonly class AnalyticsClassifyAccessesCommand
{
    public function __construct(
        private AnalyticsAccessBackfillService $backfillService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write the classification; without it the command only previews.')]
        bool $apply = false,
    ): int {
        $dryRun = !$apply;
        $result = $this->backfillService->classifyStoredRequests($dryRun);

        $message = sprintf(
            '%s %d unclassified request(s); %d of them count as accesses.',
            $dryRun ? 'Would classify' : 'Classified',
            $result->rowsClassified,
            $result->rowsCounted,
        );

        if ($dryRun) {
            $io->note($message.' Run with --apply to write.');
        } else {
            $io->success($message);
        }

        return Command::SUCCESS;
    }
}
