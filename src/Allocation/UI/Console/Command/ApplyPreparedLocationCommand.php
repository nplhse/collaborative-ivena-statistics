<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocationApplier;
use App\Allocation\Domain\Entity\Hospital;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geo:apply-prepared-location',
    description: 'Apply a prepared location to a hospital without network access (default: dry-run preview).',
)]
final readonly class ApplyPreparedLocationCommand
{
    public function __construct(
        private PreparedLocationApplier $applier,
        private HospitalLookupInterface $hospitalLookup,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Slug of the prepared location', name: 'slug')]
        string $slug,
        #[Option(description: 'Hospital (Doctrine ID) that receives the location', name: 'hospital-id')]
        ?int $hospitalId = null,
        #[Option(description: 'Preview the changes (default)', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Write coordinates, empty address and isochrone file', name: 'apply')]
        bool $apply = false,
        #[Option(description: 'Replace different existing coordinates', name: 'force')]
        bool $force = false,
    ): int {
        if ($apply && $dryRun) {
            $io->warning('Both --apply and --dry-run were passed; --apply takes precedence.');
        }

        if (null === $hospitalId) {
            $io->error('Specify --hospital-id.');

            return Command::FAILURE;
        }

        $hospital = $this->hospitalLookup->findById($hospitalId);
        if (!$hospital instanceof Hospital) {
            $io->error(sprintf('Unknown hospital id %d.', $hospitalId));

            return Command::FAILURE;
        }

        $io->title(sprintf('Apply prepared location "%s" to %s', $slug, $hospital->getName() ?? '#'.$hospitalId));
        $report = $this->applier->run($slug, $hospital, $apply, $force);

        if ([] !== $report->rows) {
            $io->table(['Field', 'Hospital', 'Prepared', 'Status'], $report->rows);
        }

        if (!$report->success) {
            $io->error($report->error ?? 'Applying the prepared location failed.');

            return Command::FAILURE;
        }

        if (!$report->changed) {
            $io->success('The hospital already uses this location. Nothing to do.');
        } elseif ($report->dryRun) {
            $io->success('Dry run finished. Re-run with --apply to write the changes.');
        } else {
            $io->success('Prepared location applied.');
        }

        return Command::SUCCESS;
    }
}
