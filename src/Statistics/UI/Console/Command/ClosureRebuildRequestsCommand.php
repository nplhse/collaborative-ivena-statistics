<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:statistics:closure-rebuild-requests',
    description: 'List unconsumed closure rebuild requests and re-queue failed ones.',
)]
final readonly class ClosureRebuildRequestsCommand
{
    public function __construct(private ClosureRebuildRequestInterface $requests)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Limit to one hospital id (requests for all hospitals are included)')]
        ?int $hospital = null,
        #[Option(description: 'Return failed requests to the queue and schedule a rebuild')]
        bool $retryFailed = false,
    ): int {
        $io->title('Closure rebuild requests');

        if ($retryFailed) {
            $count = $this->requests->retryFailed($hospital);
            $io->success(sprintf('%d failed requests re-queued.', $count));
        }

        $rows = $this->requests->unconsumed($hospital);
        if ([] === $rows) {
            $io->success('No unconsumed requests.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Id', 'Kind', 'Hospital', 'Range', 'Requested', 'State', 'Attempts', 'Last error'],
            array_map(static fn (array $row): array => [
                $row['id'],
                $row['kind'],
                $row['hospital_id'] ?? 'all',
                null === $row['range_from'] || null === $row['range_to'] ? 'whole hospital' : substr($row['range_from'], 0, 10).' – '.substr($row['range_to'], 0, 10),
                $row['requested_at'],
                match (true) {
                    null !== $row['failed_at'] => 'failed '.$row['failed_at'],
                    null !== $row['claimed_by'] => sprintf('claimed %s by %s', (string) $row['claimed_at'], $row['claimed_by']),
                    default => 'open',
                },
                $row['claim_attempts'],
                null === $row['last_error'] ? '' : mb_strimwidth($row['last_error'], 0, 80, '…'),
            ], $rows),
        );
        $io->note('A claimed request belongs to the running rebuild, or to a run that ended; the next rebuild claims it again.');
        if (!$retryFailed && [] !== array_filter($rows, static fn (array $row): bool => null !== $row['failed_at'])) {
            $io->note('Re-queue failed requests with --retry-failed after fixing the cause.');
        }

        return Command::SUCCESS;
    }
}
