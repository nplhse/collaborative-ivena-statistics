<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartDataReducer;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartSpecBuilder;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisTableRowLimit;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Controller\GenericAnalysisChartViewModelFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisChartViewModelFactoryTest extends TestCase
{
    private GenericAnalysisChartViewModelFactory $factory;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => [] === $params ? $id : $id.'|'.json_encode($params),
        );

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static function (string $name, array $params = []): string {
                $presetKey = $params['presetKey'] ?? '';
                unset($params['presetKey']);
                $query = http_build_query($params);

                return '/statistics/generic-analysis/'.$presetKey.('' !== $query ? '?'.$query : '');
            },
        );

        $reducer = new GenericAnalysisChartDataReducer(
            new DimensionRegistry(),
            new MetricRegistry(),
            $translator,
        );
        $this->factory = new GenericAnalysisChartViewModelFactory(
            GenericAnalysisTestFixtures::configurationValidator(
                new DimensionRegistry(),
                new MetricRegistry(),
                $translator,
            ),
            new GenericAnalysisChartSpecBuilder($reducer, new MetricRegistry()),
            $reducer,
            new DimensionRegistry(),
            new MetricRegistry(),
            new StatisticsNavigationUrlBuilder($router),
            $translator,
        );
    }

    public function testAllocationsByMonthHasBarSpec(): void
    {
        $query = new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );
        $result = GenericAnalysisTestFixtures::normalizedResult(
            grandTotal: 15,
            chartData: [
                'type' => 'bar',
                'labels' => ['Jan', 'Feb'],
                'values' => [10, 5],
            ],
        );

        $viewModel = $this->factory->create(Request::create('/'), 'allocations_by_month', $query, $result);

        self::assertTrue($viewModel->hasChart);
        self::assertSame('bar', $viewModel->defaultChartType);
        self::assertNotNull($viewModel->initialSpec);
        self::assertArrayHasKey('bar', $viewModel->specsByChartType);
        self::assertTrue($viewModel->showChartTypeSelector);
        self::assertFalse($viewModel->showRowLimitControl);
    }

    public function testEmptyResultShowsEmptyState(): void
    {
        $query = new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );
        $result = GenericAnalysisTestFixtures::normalizedResult(
            chartData: ['labels' => [], 'values' => []],
        );

        $viewModel = $this->factory->create(Request::create('/'), 'allocations_by_month', $query, $result);

        self::assertFalse($viewModel->hasChart);
        self::assertNull($viewModel->initialSpec);
    }

    public function testUrgencyByMonthIncludesTopLimitedWarningWhenManyHospitals(): void
    {
        $rows = [];
        $labels = [];
        $values = [];
        for ($i = 1; $i <= 8; ++$i) {
            $labels[] = 'Hospital '.$i;
            $values[] = 100 - $i;
            $rows[] = GenericAnalysisTestFixtures::enrichedRow((string) $i, 'Hospital '.$i, 100 - $i);
        }

        $viewModel = $this->factory->create(
            Request::create('/'),
            'allocations_by_hospital_cohort',
            new AnalysisQuery(
                primaryDimensionKey: 'hospital',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
            ),
            GenericAnalysisTestFixtures::normalizedResult(
                rows: $rows,
                grandTotal: array_sum($values),
                chartData: ['labels' => $labels, 'values' => $values],
            ),
        );

        self::assertTrue($viewModel->hasChart);
        self::assertNotEmpty($viewModel->warnings);
        self::assertStringContainsString('top_limited', $viewModel->warnings[0]);
    }

    public function testShowsRowLimitControlWhenManyCategoricalBuckets(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; ++$i) {
            $rows[] = GenericAnalysisTestFixtures::enrichedRow((string) $i, 'Bucket '.$i, $i);
        }

        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: $rows,
            grandTotal: 36,
            chartData: [
                'labels' => array_map(static fn (int $i): string => 'Bucket '.$i, range(1, 8)),
                'values' => range(1, 8),
            ],
        );

        $viewModel = $this->factory->create(
            Request::create('/'),
            'allocations_by_hospital',
            new AnalysisQuery(
                primaryDimensionKey: 'hospital',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
            ),
            $result,
        );

        self::assertTrue($viewModel->showRowLimitControl);
        self::assertSame(GenericAnalysisTableRowLimit::Top5, $viewModel->activeRowLimit);
        self::assertStringContainsString(
            'ga_top=5',
            $viewModel->rowLimitUrls[5],
        );
    }

    public function testAgeGroupShowsAllBucketsWithoutRowLimitControl(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; ++$i) {
            $rows[] = GenericAnalysisTestFixtures::enrichedRow((string) $i, 'Bucket '.$i, $i);
        }

        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: $rows,
            grandTotal: 36,
            chartData: [
                'labels' => array_map(static fn (int $i): string => 'Bucket '.$i, range(1, 8)),
                'values' => range(1, 8),
            ],
        );

        $viewModel = $this->factory->create(
            Request::create('/'),
            'age_group_distribution',
            new AnalysisQuery(
                primaryDimensionKey: 'age_group',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
            ),
            $result,
        );

        self::assertFalse($viewModel->showRowLimitControl);
        self::assertSame(GenericAnalysisTableRowLimit::All, $viewModel->activeRowLimit);
        self::assertEmpty($viewModel->warnings);
    }
}
