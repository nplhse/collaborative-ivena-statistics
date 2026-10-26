<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Command;

use App\Import\Application\Service\ImportDataCoverageCalculator;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills `import.data_coverage` for imports that finished before the summary existed.
 *
 * Import runs calculate the summary before deduplication. A backfill can only read the rows
 * that are still stored, so rows replaced by later imports are missing from it.
 */
#[AsCommand(
    name: 'app:import:derive-data-coverage',
    description: 'Calculate the data coverage summary of completed imports (dry run unless --apply).',
)]
final readonly class DeriveImportDataCoverageCommand
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private ImportDataCoverageCalculator $calculator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write the calculated summaries')]
        bool $apply = false,
        #[Option(description: 'Limit to one hospital id')]
        ?int $hospital = null,
        #[Option(description: 'Recalculate imports that already have a summary')]
        bool $all = false,
    ): int {
        $rows = [];
        $changed = 0;
        $lastId = 0;
        do {
            $imports = $this->batch($lastId, $hospital, $all);
            foreach ($imports as $import) {
                $lastId = (int) $import->getId();
                $coverage = $this->calculator->calculate($import);
                $stored = $import->getDataCoverage();
                if ($apply ? !$import->applyDataCoverage($coverage) : $this->same($stored, $coverage)) {
                    continue;
                }
                ++$changed;
                $rows[] = [
                    $lastId,
                    (int) $import->getHospital()?->getId(),
                    (string) $import->getType()?->value,
                    $this->label($stored),
                    $this->label($coverage),
                ];
            }
            if ($apply) {
                $this->entityManager->flush();
            }
            $this->entityManager->clear();
        } while (self::BATCH_SIZE === \count($imports));

        if ([] !== $rows) {
            $io->table(['Import', 'Hospital', 'Type', 'Stored', 'Calculated'], $rows);
        }
        $io->success(\sprintf(
            '%s %d import data coverage summar%s.%s',
            $apply ? 'Updated' : 'Would update',
            $changed,
            1 === $changed ? 'y' : 'ies',
            $apply ? '' : ' Run with --apply to write.',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<Import>
     */
    private function batch(int $afterId, ?int $hospital, bool $all): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Import::class, 'i')
            ->where('i.id > :after')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('after', $afterId)
            ->setParameter('statuses', [ImportStatus::COMPLETED, ImportStatus::PARTIAL])
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(self::BATCH_SIZE);
        if (null !== $hospital) {
            $builder->andWhere('IDENTITY(i.hospital) = :hospital')->setParameter('hospital', $hospital);
        }
        if (!$all) {
            $builder->andWhere('i.dataCoverage IS NULL');
        }

        /** @var list<Import> $imports */
        $imports = $builder->getQuery()->getResult();

        return $imports;
    }

    private function same(?ImportDataCoverage $left, ?ImportDataCoverage $right): bool
    {
        return $left?->toArray() == $right?->toArray();
    }

    private function label(?ImportDataCoverage $coverage): string
    {
        if (!$coverage instanceof ImportDataCoverage) {
            return '–';
        }
        if ($coverage->isEmpty()) {
            return 'no records';
        }

        return \sprintf(
            '%s → %s, %d/%d days, %d gap(s)',
            (string) $coverage->firstDay()?->format('Y-m-d'),
            (string) $coverage->lastDay()?->format('Y-m-d'),
            $coverage->daysWithData(),
            $coverage->periodDays(),
            \count($coverage->gaps()),
        );
    }
}
