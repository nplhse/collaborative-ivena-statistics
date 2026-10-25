<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\ExportPeriod\ExportPeriod;
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportExportPeriodSource;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:derive-export-periods',
    description: 'Derive import export periods from imported rows (dry run unless --apply). Manually maintained periods are kept.',
)]
final readonly class DeriveImportExportPeriodsCommand
{
    public function __construct(
        private ImportExportPeriodDeriver $deriver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write the derived periods and request closure rebuilds')]
        bool $apply = false,
        #[Option(description: 'Limit to one hospital id')]
        ?int $hospital = null,
        #[Option(description: 'List unchanged imports as well', name: 'show-unchanged')]
        bool $showUnchanged = false,
    ): int {
        $rows = [];
        $changedImports = 0;
        $queued = [];
        $notQueued = [];
        foreach ($this->hospitalIds($hospital) as $hospitalId) {
            $allocationPeriods = [];
            $allocationChanged = false;
            foreach ($this->imports($hospitalId, ImportType::ALLOCATION) as $import) {
                [$period, $changed] = $this->process($import, null, $apply, $showUnchanged, $rows);
                $allocationChanged = $allocationChanged || $changed;
                $changedImports += $changed ? 1 : 0;
                $stored = ImportExportPeriodSource::MANUAL === $import->getExportPeriodSource()
                    ? $this->stored($import)
                    : $period;
                if ($stored instanceof ExportPeriod) {
                    $allocationPeriods[] = $stored;
                }
            }
            $closureChanged = false;
            foreach ($this->imports($hospitalId, ImportType::CLOSURE) as $import) {
                [, $changed] = $this->process($import, $allocationPeriods, $apply, $showUnchanged, $rows);
                $closureChanged = $closureChanged || $changed;
                $changedImports += $changed ? 1 : 0;
            }
            if ($apply) {
                $this->entityManager->flush();
                if ($allocationChanged || $closureChanged) {
                    if ($this->deriver->notifyCoverageChanged($hospitalId)) {
                        $queued[] = $hospitalId;
                    } else {
                        $notQueued[] = $hospitalId;
                    }
                }
            }
            $this->entityManager->clear();
        }

        if ([] !== $rows) {
            $io->table(['Import', 'Hospital', 'Type', 'Source', 'Stored period', 'Derived period', 'Changed'], $rows);
        }
        $verb = $apply ? 'Updated' : 'Would update';
        $io->success(sprintf('%s %d import export period(s).%s', $verb, $changedImports, $apply ? '' : ' Run with --apply to write.'));
        if ([] !== $queued) {
            $io->text(sprintf('Closure rebuild queued for hospitals: %s', implode(', ', $queued)));
        }
        if ([] !== $notQueued) {
            $io->text(sprintf('No closure rebuild queued (no closure data or already queued) for hospitals: %s', implode(', ', $notQueued)));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<ExportPeriod>|null $allocationPeriods
     * @param list<list<string|int>>  $rows
     *
     * @return array{0: ?ExportPeriod, 1: bool}
     */
    private function process(Import $import, ?array $allocationPeriods, bool $apply, bool $showUnchanged, array &$rows): array
    {
        $period = $this->deriver->derive($import, $allocationPeriods);
        $stored = $this->stored($import);
        $manual = ImportExportPeriodSource::MANUAL === $import->getExportPeriodSource();
        $changed = !$manual && !$this->same($stored, $period);
        if ($changed || $showUnchanged) {
            $rows[] = [
                (int) $import->getId(),
                (int) $import->getHospital()?->getId(),
                (string) $import->getType()?->value,
                $import->getExportPeriodSource()->value ?? '–',
                $this->label($stored),
                $this->label($period),
                $changed ? 'yes' : ($manual ? 'manual, kept' : 'no'),
            ];
        }
        if ($apply && $changed) {
            $import->applyDerivedExportPeriod($period?->startsAt, $period?->endsAt);
        }

        return [$period, $changed];
    }

    private function stored(Import $import): ?ExportPeriod
    {
        $startsAt = $import->getExportStartsAt();
        $endsAt = $import->getExportEndsAt();
        if (!$startsAt instanceof \DateTimeImmutable || !$endsAt instanceof \DateTimeImmutable || $endsAt <= $startsAt) {
            return null;
        }

        return new ExportPeriod($startsAt, $endsAt);
    }

    private function same(?ExportPeriod $left, ?ExportPeriod $right): bool
    {
        if (!$left instanceof ExportPeriod || !$right instanceof ExportPeriod) {
            return $left === $right;
        }

        return $left->equals($right);
    }

    private function label(?ExportPeriod $period): string
    {
        if (!$period instanceof ExportPeriod) {
            return '–';
        }

        return $period->startsAt->format('Y-m-d H:i').' → '.$period->endsAt->format('Y-m-d H:i');
    }

    /**
     * @return list<int>
     */
    private function hospitalIds(?int $hospital): array
    {
        if (null !== $hospital) {
            return [$hospital];
        }
        /** @var list<int|string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT hospital_id FROM import ORDER BY hospital_id ASC',
        );

        return array_map(intval(...), $ids);
    }

    /**
     * @return list<Import>
     */
    private function imports(int $hospitalId, ImportType $type): array
    {
        return $this->entityManager->getRepository(Import::class)->findBy(
            [
                'hospital' => $hospitalId,
                'type' => $type,
                'status' => [ImportStatus::COMPLETED, ImportStatus::PARTIAL],
            ],
            ['id' => 'ASC'],
        );
    }
}
