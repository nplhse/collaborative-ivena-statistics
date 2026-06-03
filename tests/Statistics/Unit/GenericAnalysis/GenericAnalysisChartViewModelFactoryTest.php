<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartDataReducer;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartRecommendationService;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartSpecBuilder;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Controller\GenericAnalysisChartViewModelFactory;
use PHPUnit\Framework\TestCase;
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

        $reducer = new GenericAnalysisChartDataReducer(new DimensionRegistry(), $translator);
        $this->factory = new GenericAnalysisChartViewModelFactory(
            new GenericAnalysisChartRecommendationService(new DimensionRegistry(), $translator),
            new GenericAnalysisChartSpecBuilder($reducer),
            $reducer,
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
        $result = new NormalizedAnalysisResult(
            title: 'Allocations by month',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: null,
            grandTotal: 15,
            rows: [],
            chartData: [
                'type' => 'bar',
                'labels' => ['Jan', 'Feb'],
                'values' => [10, 5],
            ],
        );

        $viewModel = $this->factory->create($query, $result);

        self::assertTrue($viewModel->hasChart);
        self::assertSame('bar', $viewModel->defaultChartType);
        self::assertNotNull($viewModel->initialSpec);
        self::assertArrayHasKey('bar', $viewModel->specsByChartType);
        self::assertTrue($viewModel->showChartTypeSelector);
    }

    public function testEmptyResultShowsEmptyState(): void
    {
        $query = new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        );
        $result = new NormalizedAnalysisResult(
            title: 'Allocations by month',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: null,
            grandTotal: 0,
            rows: [],
            chartData: ['labels' => [], 'values' => []],
        );

        $viewModel = $this->factory->create($query, $result);

        self::assertFalse($viewModel->hasChart);
        self::assertNull($viewModel->initialSpec);
    }

    public function testUrgencyByMonthIncludesTopLimitedWarningWhenManyHospitals(): void
    {
        $labels = [];
        $values = [];
        for ($i = 1; $i <= 8; ++$i) {
            $labels[] = 'Hospital '.$i;
            $values[] = 100 - $i;
        }

        $viewModel = $this->factory->create(
            new AnalysisQuery(
                primaryDimensionKey: 'hospital',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
            ),
            new NormalizedAnalysisResult(
                title: 'By hospital',
                primaryDimensionLabel: 'Hospital',
                seriesDimensionLabel: null,
                grandTotal: array_sum($values),
                rows: [],
                chartData: ['labels' => $labels, 'values' => $values],
            ),
        );

        self::assertTrue($viewModel->hasChart);
        self::assertStringContainsString('top_limited', $viewModel->warnings[0]);
    }
}
