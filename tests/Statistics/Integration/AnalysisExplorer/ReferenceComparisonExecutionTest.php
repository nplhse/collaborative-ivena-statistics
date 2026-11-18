<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Statistics\AnalysisExplorer\Application\AnalysisExecution;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Reference hospital comparison (#1346) on PostgreSQL with a hand-checkable fixture, March 2024, scope "all":
 *
 * | Hospital | X | Y |
 * |----------|---|---|
 * | A        | 3 | 1 |
 * | B        | 1 | 2 |
 * | C        | 2 | 0 |
 * | D        | 1 | 1 |
 * | E        | 0 | 1 |
 *
 * Without comparison X = 7, Y = 5. Reference A: A X 3 (75 %), Y 1 (25 %); others X 4 (50 %), Y 4 (50 %).
 * References A and B: B X 1 (33.33 %), Y 2 (66.67 %); others (C+D+E) X 3 (60 %), Y 2 (40 %).
 */
#[ResetDatabase]
final class ReferenceComparisonExecutionTest extends KernelTestCase
{
    use Factories;

    private const int X = 101;

    private const int Y = 102;

    /** @var array<string, int> hospital id by letter */
    private array $hospitals = [];

    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            $this->hospitals[$letter] = (int) HospitalFactory::createOne(['name' => 'Hospital '.$letter])->getId();
        }
        $this->admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        $this->insert([
            'A' => [self::X => 3, self::Y => 1],
            'B' => [self::X => 1, self::Y => 2],
            'C' => [self::X => 2],
            'D' => [self::X => 1, self::Y => 1],
            'E' => [self::Y => 1],
        ], '2024-03-10');
    }

    public function testWithoutComparisonTheNumbersStayAsTheyAre(): void
    {
        $rows = $this->execute($this->config([]))->result->rows;

        self::assertSame([(string) self::X => 7, (string) self::Y => 5], $this->counts($rows, null));
        self::assertSame([], array_filter($rows, static fn (AnalysisResultRow $row): bool => null !== $row->seriesKey));
    }

    public function testOneReferenceAgainstTheCaseWeightedRest(): void
    {
        $execution = $this->execute($this->config(['A']));
        $rows = $execution->result->rows;
        $a = (string) $this->hospitals['A'];

        self::assertNull($execution->warningKey);
        self::assertSame([(string) self::X => 3, (string) self::Y => 1], $this->counts($rows, $a));
        self::assertSame([(string) self::X => 4, (string) self::Y => 4], $this->counts($rows, 'others'));
        self::assertSame([(string) self::X => 75.0, (string) self::Y => 25.0], $this->shares($rows, $a));
        self::assertSame([(string) self::X => 50.0, (string) self::Y => 50.0], $this->shares($rows, 'others'));
        self::assertSame('Hospital A', $this->seriesLabel($rows, $a));
        self::assertSame('Other hospitals in scope', $this->seriesLabel($rows, 'others'));
        self::assertSame(12.0, (float) $execution->result->totalFor(AnalysisMetricKey::AllocationCount), 'Groups add up to the population.');
    }

    public function testTwoReferencesGiveThreeDisjointGroups(): void
    {
        $rows = $this->execute($this->config(['A', 'B']))->result->rows;
        $b = (string) $this->hospitals['B'];

        self::assertSame([(string) self::X => 1, (string) self::Y => 2], $this->counts($rows, $b));
        self::assertSame([(string) self::X => 33.33, (string) self::Y => 66.67], $this->shares($rows, $b));
        self::assertSame([(string) self::X => 3, (string) self::Y => 2], $this->counts($rows, 'others'));
        self::assertSame([(string) self::X => 60.0, (string) self::Y => 40.0], $this->shares($rows, 'others'));
    }

    public function testAnEmptyBucketInAGroupWithCasesIsZeroPercent(): void
    {
        // C has only X: with reference C, bucket Y of C is filled with 0 cases and 0 %.
        $rows = $this->execute($this->config(['C']))->result->rows;
        $c = (string) $this->hospitals['C'];

        self::assertSame([(string) self::X => 2, (string) self::Y => 0], $this->counts($rows, $c));
        self::assertSame([(string) self::X => 100.0, (string) self::Y => 0.0], $this->shares($rows, $c));
    }

    public function testAReferenceWithoutCasesHasNoShare(): void
    {
        // April: only C has a case, so A is an empty group.
        $this->insert(['C' => [self::X => 1]], '2024-04-05');
        $rows = $this->execute($this->config(['A'], month: 4))->result->rows;
        $a = (string) $this->hospitals['A'];

        self::assertSame([(string) self::X => 0], $this->counts($rows, $a));
        self::assertSame([(string) self::X => null], $this->shares($rows, $a));
        self::assertSame([(string) self::X => 100.0], $this->shares($rows, 'others'));
    }

    public function testTheChartLimitDoesNotChangeTheShares(): void
    {
        // Six buckets for the others, chart limited to the top 5: the table keeps all six shares of the full group.
        foreach ([103, 104, 105, 106] as $indication) {
            $this->insert(['D' => [$indication => 1]], '2024-03-12');
        }
        $execution = $this->execute($this->config(['A'])->withPresentation(
            $this->config(['A'])->presentation->withChartRowLimit(ExplorerChartRowLimit::Top5),
        ));

        $others = $this->shares($execution->result->rows, 'others');
        self::assertCount(6, $others);
        self::assertEqualsWithDelta(100.0, array_sum($others), 0.05);
        self::assertArrayHasKey((string) self::X, $others);
        self::assertSame(33.33, $others[(string) self::X], 'X: 4 of 12 cases of the others.');
    }

    public function testTheDistributionPathRunsWithTheComparison(): void
    {
        $config = $this->config(['A'])
            ->withMetrics([AnalysisMetricKey::TransportTimeDistribution], AnalysisMetricKey::TransportTimeDistribution)
            ->withPresentation(new \App\Statistics\AnalysisExplorer\Domain\PresentationConfig(chartType: ChartPresentationType::BoxPlot));

        $execution = $this->execute($config);

        self::assertNotSame('query_error', $execution->emptyReason);
        $series = array_values(array_unique(array_map(static fn (AnalysisResultRow $row): ?string => $row->seriesKey, $execution->result->rows)));
        sort($series);
        self::assertSame([(string) $this->hospitals['A'], 'others'], $series);
    }

    private function execute(AnalysisViewConfig $config): \App\Statistics\AnalysisExplorer\Application\DTO\AnalysisExecutionResult
    {
        return self::getContainer()->get(AnalysisExecution::class)->execute($config, $this->admin);
    }

    /**
     * @param list<string> $references letters
     */
    private function config(array $references, int $month = 3): AnalysisViewConfig
    {
        $config = self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault(new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Month,
            referenceYear: 2024,
            referenceMonth: $month,
        ))->withAxes(AnalysisAxisRef::breakdown(AnalysisDimensionKey::Indication), null);

        if ([] === $references) {
            return $config;
        }

        return $config
            ->withReferenceHospitalIds(array_map(fn (string $letter): int => $this->hospitals[$letter], $references))
            ->withMetrics([AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfSeries], AnalysisMetricKey::AllocationCount);
    }

    /**
     * @param array<string, array<int, int>> $casesByHospital indication => number of cases, by hospital letter
     */
    private function insert(array $casesByHospital, string $day): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $id = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM allocation_stats_projection');
        $date = new \DateTimeImmutable($day.' 08:00:00');

        foreach ($casesByHospital as $letter => $cases) {
            foreach ($cases as $indication => $count) {
                for ($i = 0; $i < $count; ++$i) {
                    $connection->insert('allocation_stats_projection', [
                        'id' => ++$id,
                        'import_id' => 1,
                        'hospital_id' => $this->hospitals[$letter],
                        'state_id' => 1,
                        'dispatch_area_id' => 1,
                        'speciality_id' => 1,
                        'department_id' => 1,
                        'assignment_id' => 1,
                        'indication_normalized_id' => $indication,
                        'created_at' => $date->format('Y-m-d H:i:s'),
                        'arrival_at' => $date->modify('+20 minutes')->format('Y-m-d H:i:s'),
                        'created_year' => (int) $date->format('Y'),
                        'created_quarter' => (int) ceil((int) $date->format('n') / 3),
                        'created_month' => (int) $date->format('n'),
                        'created_week' => (int) $date->format('W'),
                        'created_day' => (int) $date->format('j'),
                        'created_weekday' => (int) $date->format('N'),
                        'created_hour' => 8,
                        'day_time_bucket_code' => 1,
                        'shift_bucket_code' => 1,
                        'transport_time_minutes' => 20,
                        'urgency_code' => 1,
                    ]);
                }
            }
        }
    }

    /**
     * @param list<AnalysisResultRow> $rows
     *
     * @return array<string, int|float|null>
     */
    private function counts(array $rows, ?string $series): array
    {
        return $this->values($rows, $series, AnalysisMetricKey::AllocationCount);
    }

    /**
     * @param list<AnalysisResultRow> $rows
     *
     * @return array<string, int|float|null>
     */
    private function shares(array $rows, string $series): array
    {
        return $this->values($rows, $series, AnalysisMetricKey::PercentOfSeries);
    }

    /**
     * @param list<AnalysisResultRow> $rows
     *
     * @return array<string, int|float|null>
     */
    private function values(array $rows, ?string $series, AnalysisMetricKey $metric): array
    {
        $values = [];
        foreach ($rows as $row) {
            if ($row->seriesKey === $series) {
                $value = $row->valueFor($metric);
                $values[$row->bucket] = \is_float($value) && AnalysisMetricKey::AllocationCount === $metric ? (int) $value : $value;
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * @param list<AnalysisResultRow> $rows
     */
    private function seriesLabel(array $rows, string $series): ?string
    {
        foreach ($rows as $row) {
            if ($row->seriesKey === $series) {
                return $row->seriesLabel;
            }
        }

        return null;
    }
}
