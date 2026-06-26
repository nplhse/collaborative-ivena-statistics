<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckQuery;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckResult;
use App\Allocation\Infrastructure\Query\IndicationRawReviewHealthCheckSeverity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:allocation:audit-indication-review',
    description: 'Audit indication raw review data consistency (legacy migration, allocations, projection).',
)]
final readonly class AuditIndicationReviewCommand
{
    public function __construct(
        private IndicationRawReviewHealthCheckQuery $healthCheckQuery,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Output results as JSON')]
        bool $json = false,
        #[Option(description: 'Show sample row IDs for WARN/FAIL checks', name: 'show-samples')]
        bool $showSamples = false,
    ): int {
        $results = $this->healthCheckQuery->runAll();
        $hasFailures = $this->hasFailures($results);

        if ($json) {
            $io->writeln(json_encode(
                array_map(static fn (IndicationRawReviewHealthCheckResult $result): array => [
                    'id' => $result->id,
                    'label' => $result->label,
                    'count' => $result->count,
                    'severity' => $result->severity->value,
                    'status' => $result->statusLabel(),
                    'hint' => $result->hint,
                ], $results),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            ));

            return $hasFailures ? Command::FAILURE : Command::SUCCESS;
        }

        $io->title('Indication raw review health check');

        $io->table(
            ['Check', 'Count', 'Status', 'Hint'],
            array_map(
                static fn (IndicationRawReviewHealthCheckResult $result): array => [
                    $result->label,
                    (string) $result->count,
                    $result->statusLabel(),
                    $result->hint,
                ],
                $results,
            ),
        );

        if ($showSamples) {
            $this->renderSamples($io, $results);
        }

        if ($hasFailures) {
            $io->error('One or more FAIL checks reported issues.');

            return Command::FAILURE;
        }

        $io->success('No FAIL checks reported issues.');

        return Command::SUCCESS;
    }

    /**
     * @param list<IndicationRawReviewHealthCheckResult> $results
     */
    private function hasFailures(array $results): bool
    {
        return array_any($results, fn (IndicationRawReviewHealthCheckResult $result): bool => $result->isFailing());
    }

    /**
     * @param list<IndicationRawReviewHealthCheckResult> $results
     */
    private function renderSamples(SymfonyStyle $io, array $results): void
    {
        $printed = false;

        foreach ($results as $result) {
            if (IndicationRawReviewHealthCheckSeverity::Info === $result->severity || 0 === $result->count) {
                continue;
            }

            $sampleIds = $this->healthCheckQuery->fetchSampleIds($result->id);
            if ([] === $sampleIds) {
                continue;
            }

            if (!$printed) {
                $io->section('Sample IDs');
                $printed = true;
            }

            $io->writeln(sprintf(
                '  %s: %s',
                $result->id,
                implode(', ', array_map(static fn (int $id): string => (string) $id, $sampleIds)),
            ));
        }
    }
}
