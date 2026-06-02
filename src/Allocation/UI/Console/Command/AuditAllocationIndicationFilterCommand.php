<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Command;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:audit-allocation-indication-filter',
    description: 'Compare EXPLAIN-based result estimates with actual allocation rows per normalized indication code.',
)]
final class AuditAllocationIndicationFilterCommand extends Command
{
    public function __construct(
        private readonly ListAllocationsQuery $listAllocationsQuery,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Audit only this indication code')
            ->addOption('min-estimate', null, InputOption::VALUE_REQUIRED, 'Only report mismatches with estimate >= this value', '1')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output problematic codes as JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $minEstimate = max(0, (int) $input->getOption('min-estimate'));
        $singleCode = $input->getOption('code');
        $codes = null !== $singleCode && '' !== $singleCode
            ? [(int) $singleCode]
            : $this->loadDistinctIndicationCodes();

        $io->title(sprintf('Auditing %d indication code(s)', \count($codes)));

        $problematic = [];
        $scanned = 0;

        foreach ($codes as $code) {
            ++$scanned;
            $dto = new AllocationQueryParametersDTO(indication: $code);
            $paginator = $this->listAllocationsQuery->getPaginator($dto);
            $pageRows = iterator_to_array($paginator->getResults());
            $estimated = $paginator->getEstimatedNumResults();
            $actualNormalized = $this->countByNormalizedCode($code);
            $actualRaw = $this->countByRawCode($code);

            // #region agent log
            $this->debugLog('audit-indication', [
                'hypothesisId' => 'A',
                'code' => $code,
                'estimated' => $estimated,
                'pageRows' => \count($pageRows),
                'actualNormalized' => $actualNormalized,
                'actualRaw' => $actualRaw,
            ]);
            // #endregion

            $estimateForCompare = $estimated ?? 0;
            if ($estimateForCompare >= $minEstimate && 0 === \count($pageRows) && 0 === $actualNormalized) {
                $problematic[] = [
                    'code' => $code,
                    'estimated' => $estimated,
                    'actualNormalized' => $actualNormalized,
                    'actualRaw' => $actualRaw,
                ];
            }
        }

        if ($input->getOption('json')) {
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

    /**
     * @param array<string, mixed> $data
     */
    private function debugLog(string $message, array $data): void
    {
        $payload = json_encode([
            'sessionId' => '281c06',
            'runId' => 'audit-command',
            'hypothesisId' => $data['hypothesisId'] ?? 'A',
            'location' => self::class,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000.0),
        ], JSON_THROW_ON_ERROR);

        $logPath = $this->projectDir.'/.cursor/debug-281c06.log';
        file_put_contents($logPath, $payload."\n", FILE_APPEND);
    }
}
