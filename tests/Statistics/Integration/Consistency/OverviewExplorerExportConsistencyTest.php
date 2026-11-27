<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Consistency;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\AnalysisExplorer\Application\AnalysisExecution;
use App\Statistics\AnalysisExplorer\Application\AnalysisRunnerRegistry;
use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerAnalysisQueryFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerResultsTableExportBuilder;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\GenericAnalysis\Application\Export\CsvTabularExporter;
use App\Statistics\Infrastructure\Query\Overview\GetOverviewDashboardMetricsQuery;
use App\Statistics\Infrastructure\Query\Overview\OverviewQueryCriteria;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;

/**
 * The same number on three surfaces (#583, sample from the correctness audit #579): the Overview, the Explorer
 * on screen and the Explorer CSV export, for one hospital and the second quarter of 2025.
 *
 * Fixture, hospital H:
 * - inside [2025-04-01, 2025-07-01): 3 × SK1, 2 × SK2, 1 × SK3 → 6 allocations;
 * - outside: 2025-03-31 23:59 and 2025-07-01 00:00 (the exclusive end);
 * - another hospital: 1 × SK1 inside the quarter.
 *
 * Expected on every surface: total 6, by urgency 3 / 2 / 1.
 */
final class OverviewExplorerExportConsistencyTest extends DatabaseKernelTestCase
{
    private const array EXPECTED_BY_URGENCY = [1 => 3, 2 => 2, 3 => 1];

    public function testOverviewExplorerAndCsvExportShowTheSameNumbers(): void
    {
        self::bootKernel();
        [$owner, $hospital] = $this->seed();
        $filter = new StatisticsFilter(StatisticsFilterScope::Hospital, $hospital->getId(), null, StatisticsFilterPeriod::Quarter, 2025, null, 2);

        // Overview, with the criteria the dashboard builds for a hospital scope.
        $overview = self::getContainer()->get(GetOverviewDashboardMetricsQuery::class)(
            OverviewQueryCriteria::fromPeriodBounds(StatisticsPeriodResolver::resolve($filter), [(int) $hospital->getId()]),
        );
        self::assertSame(6, $overview->scopedTotal);
        self::assertSame(self::EXPECTED_BY_URGENCY, array_filter($overview->urgencyCounts));

        // Explorer on screen.
        $config = self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault($filter)
            ->withAxes(AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency), null);
        $screen = self::getContainer()->get(AnalysisExecution::class)->execute($config, $owner);
        self::assertNull($screen->emptyReason);
        self::assertSame(6, (int) $screen->result->totalFor(AnalysisMetricKey::AllocationCount));
        self::assertSame(array_values(self::EXPECTED_BY_URGENCY), $this->countsByBucket($screen->result));

        // Explorer CSV, along the path of the export controller.
        $csv = $this->exportCsv($config, $owner);
        $lines = array_values(array_filter(explode("\n", $csv)));
        self::assertSame('Total,6', end($lines));
        self::assertSame(array_values(self::EXPECTED_BY_URGENCY), array_map(
            static fn (string $line): int => (int) substr($line, strrpos($line, ',') + 1),
            \array_slice($lines, 1, -1),
        ));
    }

    /**
     * @return list<int> allocation counts of the rows in bucket order
     */
    private function countsByBucket(AnalysisRunResult $result): array
    {
        $counts = [];
        foreach ($result->rows as $row) {
            $counts[$row->bucket] = (int) $row->valueFor(AnalysisMetricKey::AllocationCount);
        }
        ksort($counts);

        return array_values($counts);
    }

    private function exportCsv(AnalysisViewConfig $config, User $user): string
    {
        $container = self::getContainer();
        $normalized = $container->get(AnalysisViewConfigNormalizer::class)->normalize($config);
        $result = $container->get(AnalysisRunnerRegistry::class)->run(
            $normalized,
            $container->get(ExplorerAnalysisQueryFactory::class)->create($normalized, $user),
        );
        $document = $container->get(ExplorerResultsTableExportBuilder::class)->build($normalized, $result);

        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        new CsvTabularExporter()->export($document, $stream);
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @return array{User, Hospital}
     */
    private function seed(): array
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'state' => $state, 'dispatchArea' => $dispatchArea]);
        $other = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
        IndicationNormalizedFactory::createOne();
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $owner]);
        $otherImport = ImportFactory::createOne(['hospital' => $other, 'createdBy' => $owner]);

        $inside = [
            ['2025-04-01 00:00', AllocationUrgency::EMERGENCY],
            ['2025-05-15 12:00', AllocationUrgency::EMERGENCY],
            ['2025-06-30 23:59', AllocationUrgency::EMERGENCY],
            ['2025-04-20 08:00', AllocationUrgency::INPATIENT],
            ['2025-06-01 08:00', AllocationUrgency::INPATIENT],
            ['2025-05-05 08:00', AllocationUrgency::OUTPATIENT],
        ];
        foreach ($inside as [$createdAt, $urgency]) {
            $this->allocation($import, $hospital, $createdAt, $urgency);
        }
        $this->allocation($import, $hospital, '2025-03-31 23:59', AllocationUrgency::EMERGENCY);
        $this->allocation($import, $hospital, '2025-07-01 00:00', AllocationUrgency::EMERGENCY);
        $this->allocation($otherImport, $other, '2025-05-15 12:00', AllocationUrgency::EMERGENCY);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport((int) $import->getId());
        $rebuilder->rebuildForImport((int) $otherImport->getId());

        return [$owner, $hospital];
    }

    private function allocation(Import $import, Hospital $hospital, string $createdAt, AllocationUrgency $urgency): void
    {
        $created = new \DateTimeImmutable($createdAt);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $hospital->getState(),
            'dispatchArea' => $hospital->getDispatchArea(),
            'urgency' => $urgency,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+20 minutes'),
        ]);
    }
}
