<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisTableLayout;
use App\Statistics\GenericAnalysis\UI\Http\Controller\GenericAnalysisTableViewModelFactory;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GenericAnalysisTableViewModelFactoryTest extends TestCase
{
    private GenericAnalysisTableViewModelFactory $factory;

    protected function setUp(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static function (string $name, array $params = []): string {
                $presetKey = $params['presetKey'] ?? '';
                unset($params['presetKey']);
                $query = http_build_query($params);

                return '/statistics/generic-analysis/'.$presetKey.('' !== $query ? '?'.$query : '');
            },
        );

        $this->factory = new GenericAnalysisTableViewModelFactory(
            new StatisticsNavigationUrlBuilder($router),
        );
    }

    public function testGroupedLayoutPivotsSeriesIntoOneRowPerBucket(): void
    {
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 15,
            rows: [
                new EnrichedAnalysisRow('1', 'Jan', 10, 66.67, 66.67, 'u1', 'U1'),
                new EnrichedAnalysisRow('1', 'Jan', 5, 33.33, 33.33, 'u2', 'U2'),
                new EnrichedAnalysisRow('2', 'Feb', 3, 100.0, 100.0, 'u1', 'U1'),
            ],
            chartData: [],
        );

        $request = Request::create('/statistics/generic-analysis/urgency_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::LAYOUT => 'grouped',
        ]);

        $viewModel = $this->factory->create($request, 'urgency_by_month', $result);

        self::assertTrue($viewModel->isGrouped());
        self::assertCount(2, $viewModel->groupedRows);
        self::assertSame(15, $viewModel->groupedRows[0]->bucketTotal);
        self::assertSame(10, $viewModel->groupedRows[0]->cellsBySeriesKey['u1']?->value);
        self::assertSame(5, $viewModel->groupedRows[0]->cellsBySeriesKey['u2']?->value);
    }

    public function testWithoutSeriesAlwaysUsesStackedLayout(): void
    {
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: null,
            grandTotal: 10,
            rows: [
                new EnrichedAnalysisRow('1', 'Jan', 10, 100.0, 100.0),
            ],
            chartData: [],
        );

        $request = Request::create('/statistics/generic-analysis/allocations_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::LAYOUT => 'grouped',
        ]);

        $viewModel = $this->factory->create($request, 'allocations_by_month', $result);

        self::assertFalse($viewModel->supportsGroupedLayout);
        self::assertFalse($viewModel->isGrouped());
        self::assertSame(GenericAnalysisTableLayout::Stacked, $viewModel->layout);
    }

    public function testLayoutToggleUrlsPreserveQueryParameter(): void
    {
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 1,
            rows: [
                new EnrichedAnalysisRow('1', 'Jan', 1, 100.0, 100.0, 'u1', 'U1'),
            ],
            chartData: [],
        );

        $request = Request::create('/statistics/generic-analysis/urgency_by_month', Request::METHOD_GET, [
            'scope' => 'public',
        ]);

        $viewModel = $this->factory->create($request, 'urgency_by_month', $result);

        self::assertStringContainsString('ga_layout=grouped', $viewModel->groupedLayoutUrl);
        self::assertStringContainsString('ga_layout=stacked', $viewModel->stackedLayoutUrl);
        self::assertStringContainsString('scope=public', $viewModel->groupedLayoutUrl);
    }

    public function testFooterTotalsSumRowsAndPercentOfGrandTotal(): void
    {
        $result = new NormalizedAnalysisResult(
            title: 'Test',
            primaryDimensionLabel: 'Month',
            seriesDimensionLabel: 'Urgency',
            grandTotal: 15,
            rows: [
                new EnrichedAnalysisRow('1', 'Jan', 10, 66.67, 66.67, 'u1', 'U1'),
                new EnrichedAnalysisRow('1', 'Jan', 5, 33.33, 33.33, 'u2', 'U2'),
            ],
            chartData: [],
        );

        $request = Request::create('/statistics/generic-analysis/urgency_by_month', Request::METHOD_GET);

        $viewModel = $this->factory->create($request, 'urgency_by_month', $result);

        self::assertSame(15, $viewModel->grandTotal);
        self::assertSame(15, $viewModel->footerTotals->totalValue);
        self::assertEqualsWithDelta(100.0, $viewModel->footerTotals->percentOfGrandTotal, 0.01);
        self::assertSame(10, $viewModel->footerTotals->seriesCellsByKey['u1']->value);
        self::assertEqualsWithDelta(66.67, $viewModel->footerTotals->seriesCellsByKey['u1']->percentOfGrandTotal, 0.1);
    }
}
