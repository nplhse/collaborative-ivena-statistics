<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Hospital\DTO\HospitalParticipatingSinceBackfillRow;
use App\Allocation\Application\Hospital\HospitalParticipatingSinceBackfillService;
use App\Allocation\UI\Console\Input\BackfillHospitalParticipatingSinceInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:hospital:backfill-participating-since',
    description: 'Fill Hospital.participatingSince from audit evidence, otherwise the first successful import (default: dry-run preview).',
)]
final readonly class BackfillHospitalParticipatingSinceCommand
{
    public function __construct(
        private HospitalParticipatingSinceBackfillService $backfillService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] BackfillHospitalParticipatingSinceInput $input,
    ): int {
        $apply = $input->apply;
        if ($apply && $input->dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }
        $dryRun = !$apply;

        $io->title('Backfill hospital participatingSince');

        if ($dryRun) {
            $io->note('Dry run: no rows will be written. Re-run with --apply to persist changes.');
        } else {
            $io->warning('Apply mode: hospital.participating_since will be set where historical evidence is found.');
        }

        $rows = $dryRun ? $this->backfillService->preview() : $this->backfillService->apply();

        $fromAudit = 0;
        $fromImport = 0;
        $stillNull = 0;
        foreach ($rows as $row) {
            $this->writeHospitalReport($io, $row, $dryRun);
            if ('audit' === $row->source) {
                ++$fromAudit;
            } elseif ('import' === $row->source) {
                ++$fromImport;
            } else {
                ++$stillNull;
            }
        }

        $changed = $fromAudit + $fromImport;

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Hospitals inspected', (string) \count($rows)],
                ['From audit_log', (string) $fromAudit],
                ['From first successful import', (string) $fromImport],
                ['No historical information found', (string) $stillNull],
                [$dryRun ? 'Hospitals would be changed' : 'Hospitals changed', (string) $changed],
            ],
        );

        if ($dryRun) {
            $io->success('Dry run finished. Re-run with --apply to persist changes.');
        } else {
            $io->success(sprintf('Updated %d hospital(s).', $changed));
        }

        return Command::SUCCESS;
    }

    private function writeHospitalReport(
        SymfonyStyle $io,
        HospitalParticipatingSinceBackfillRow $row,
        bool $dryRun,
    ): void {
        $io->writeln(sprintf('Hospital #%d – %s', $row->hospitalId, $row->hospitalName));

        if (!$row->participatingSince instanceof \DateTimeImmutable) {
            $io->writeln('Source:');
            $io->writeln('none');
            $io->writeln('Action:');
            $io->writeln('no change');
            $io->newLine();

            return;
        }

        $io->writeln('Source:');
        $io->writeln('audit' === $row->source ? 'audit_log' : 'first successful import');
        $io->writeln('Reconstructed participatingSince:');
        $io->writeln($row->participatingSince->format('Y-m-d H:i'));
        $io->writeln('Action:');
        $io->writeln($dryRun ? 'would update' : 'updated');
        $io->newLine();
    }
}
