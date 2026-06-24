<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:audit-allocation-indication-filter',
    description: 'Compare EXPLAIN-based result estimates with actual allocation rows per normalized indication code.',
)]
final readonly class AuditAllocationIndicationFilterCommand
{
    public function __construct(
        private ListAllocationsQuery $listAllocationsQuery,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Audit only this indication code')]
        ?string $code = null,
        #[Option(description: 'Only report mismatches with estimate >= this value', name: 'min-estimate')]
        int $minEstimate = 1,
        #[Option(description: 'Output problematic codes as JSON')]
        bool $json = false,
    ): int {
        $minEstimate = max(0, $minEstimate);
        $codes = null !== $code && '' !== $code
            ? [(int) $code]
            : $this->loadDistinctIndicationCodes();

        $io->title(sprintf('Auditing %d indication code(s)', \count($codes)));

        $problematic = [];
        $scanned = 0;

        foreach ($codes as $indicationCode) {
            ++$scanned;
            $dto = new AllocationQueryParametersDTO(indication: $indicationCode);
            $paginator = $this->listAllocationsQuery->getPaginator($dto);
            $pageRows = iterator_to_array($paginator->getResults());
            $estimated = $paginator->getEstimatedNumResults();
            $actualNormalized = $this->countByNormalizedCode($indicationCode);
            $actualRaw = $this->countByRawCode($indicationCode);

            $estimateForCompare = $estimated ?? 0;
            if ($estimateForCompare >= $minEstimate && 0 === \count($pageRows) && 0 === $actualNormalized) {
                $problematic[] = [
                    'code' => $indicationCode,
                    'estimated' => $estimated,
                    'actualNormalized' => $actualNormalized,
                    'actualRaw' => $actualRaw,
                ];
            }
        }

        if ($json) {
            $io->writeln(json_encode($problematic, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        if ([] === $problematic) {
            $io->success(sprintf('No mismatches found among %d code(s).', $scanned));

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'Found %d code(s) where estimate > 0 but list query returns no rows (normalized count = 0):',
            \count($problematic),
        ));
        $io->table(
            ['code', 'estimated (EXPLAIN)', 'actual normalized', 'actual raw'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['code'],
                    null === $row['estimated'] ? 'null' : (string) $row['estimated'],
                    (string) $row['actualNormalized'],
                    (string) $row['actualRaw'],
                ],
                $problematic,
            ),
        );

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function loadDistinctIndicationCodes(): array
    {
        /** @var list<int> $codes */
        $codes = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT i.code')
            ->from(IndicationNormalized::class, 'i')
            ->where('i.code > 0')
            ->orderBy('i.code', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $codes;
    }

    private function countByNormalizedCode(int $code): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Allocation::class, 'a')
            ->join('a.indicationNormalized', 'inor')
            ->where('inor.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countByRawCode(int $code): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Allocation::class, 'a')
            ->join('a.indicationRaw', 'iraw')
            ->where('iraw.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
