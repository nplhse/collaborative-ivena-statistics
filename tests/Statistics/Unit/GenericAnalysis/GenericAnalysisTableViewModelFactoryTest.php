<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\GenericAnalysisTableLayout;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisTableRowLimiter;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Controller\GenericAnalysisTableViewModelFactory;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisTableViewModelFactoryTest extends TestCase
{
    private GenericAnalysisTableViewModelFactory $factory;

    #[\Override]
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

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Other');

        $this->factory = new GenericAnalysisTableViewModelFactory(
            new StatisticsNavigationUrlBuilder($router),
            new DimensionRegistry(),
            new GenericAnalysisTableRowLimiter(new MetricValueFormatter(new MetricRegistry()), $translator),
        );
    }

    public function testGroupedLayoutPivotsSeriesIntoOneRowPerBucket(): void
    {
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 10, 66.67, 66.67, 'u1', 'U1'),
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 5, 33.33, 33.33, 'u2', 'U2'),
                GenericAnalysisTestFixtures::enrichedRow('2', 'Feb', 3, 100.0, 100.0, 'u1', 'U1'),
            ],
            seriesDimensionLabel: 'Urgency',
            grandTotal: 15,
        );

        $request = Request::create('/statistics/generic-analysis/urgency_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::LAYOUT => 'grouped',
        ]);

        $viewModel = $this->factory->create($request, 'urgency_by_month', $result, 'month');

        self::assertTrue($viewModel->isGrouped());
        self::assertCount(2, $viewModel->groupedRows);
        self::assertSame(15, $viewModel->groupedRows[0]->bucketTotal);
        self::assertSame(10, $viewModel->groupedRows[0]->cellsBySeriesKey['u1']?->value);
        self::assertSame(5, $viewModel->groupedRows[0]->cellsBySeriesKey['u2']?->value);
        self::assertCount(1, $viewModel->metricColumns);
        self::assertFalse($viewModel->showPercentOfBucket);
    }

    public function testWithoutSeriesAlwaysUsesStackedLayout(): void
    {
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 10, 100.0, 100.0)],
            grandTotal: 10,
        );

        $request = Request::create('/statistics/generic-analysis/allocations_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::LAYOUT => 'grouped',
        ]);

        $viewModel = $this->factory->create($request, 'allocations_by_month', $result, 'month');

        self::assertFalse($viewModel->supportsGroupedLayout);
        self::assertFalse($viewModel->isGrouped());
        self::assertSame(GenericAnalysisTableLayout::Stacked, $viewModel->layout);
    }

    public function testLayoutToggleUrlsPreserveQueryParameter(): void
    {
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 1, 100.0, 100.0, 'u1', 'U1')],
            seriesDimensionLabel: 'Urgency',
            grandTotal: 1,
        );

        $request = Request::create('/statistics/generic-analysis/urgency_by_month', Request::METHOD_GET, [
            'scope' => 'public',
        ]);

        $viewModel = $this->factory->create($request, 'urgency_by_month', $result, 'month');

        self::assertStringContainsString('ga_layout=grouped', $viewModel->groupedLayoutUrl);
        self::assertStringContainsString('ga_layout=stacked', $viewModel->stackedLayoutUrl);
        self::assertStringContainsString('scope=public', $viewModel->groupedLayoutUrl);
    }

    public function testGroupedLayoutWithNumericBucketAndSeriesKeys(): void
    {
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 5, 62.5, 62.5, '1', 'U1'),
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 3, 37.5, 37.5, '2', 'U2'),
                GenericAnalysisTestFixtures::enrichedRow('2', 'Feb', 8, 100.0, 100.0, '1', 'U1'),
            ],
            seriesDimensionLabel: 'Urgency',
            grandTotal: 8,
        );

        $request = Request::create('/statistics/generic-analysis/custom', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::LAYOUT => 'grouped',
        ]);

        $viewModel = $this->factory->create($request, 'custom', $result, 'month');

        self::assertTrue($viewModel->isGrouped());
        self::assertCount(2, $viewModel->groupedRows);
        self::assertSame('1', $viewModel->groupedRows[0]->bucketKey);
        self::assertIsString($viewModel->groupedRows[0]->bucketKey);
        self::assertCount(2, $viewModel->seriesColumns);
        $firstSeriesKey = $viewModel->seriesColumns[0]['key'];
        $secondSeriesKey = $viewModel->seriesColumns[1]['key'];
        self::assertSame(5, $viewModel->groupedRows[0]->cellsBySeriesKey[$firstSeriesKey]?->value);
        self::assertSame(3, $viewModel->groupedRows[0]->cellsBySeriesKey[$secondSeriesKey]?->value);
    }

    public function testFooterTotalsSumRowsAndPercentOfGrandTotal(): void
    {
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 10, 66.67, 66.67, 'u1', 'U1'),
                GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 5, 33.33, 33.33, 'u2', 'U2'),
            ],
            seriesDimensionLabel: 'Urgency',
            grandTotal: 15,
            metricKeys: ['count', 'percent_of_total', 'percent_of_bucket'],
        );

        $request = Request::create('/statistics/generic-analysis/urgency_by_month', Request::METHOD_GET);

        $viewModel = $this->factory->create($request, 'urgency_by_month', $result, 'month');

        self::assertSame(15, $viewModel->grandTotal);
        self::assertSame(15, $viewModel->footerTotals->totalValue);
        self::assertEqualsWithDelta(100.0, $viewModel->footerTotals->percentOfGrandTotal, 0.01);
        self::assertSame(10, $viewModel->footerTotals->seriesCellsByKey['u1']->value);
        self::assertEqualsWithDelta(66.67, $viewModel->footerTotals->seriesCellsByKey['u1']->percentOfGrandTotal, 0.1);
        self::assertTrue($viewModel->showPercentOfBucket);
        self::assertTrue($viewModel->showPercentOfTotal);
    }

    public function testStackedLayoutExposesMetricColumns(): void
    {
        $result = GenericAnalysisTestFixtures::normalizedResult(
            rows: [GenericAnalysisTestFixtures::enrichedRow('1', 'Jan', 5, 50.0, 100.0)],
            grandTotal: 10,
            metricKeys: ['count', 'percent_of_total'],
        );

        $request = Request::create('/statistics/generic-analysis/allocations_by_month_with_share', Request::METHOD_GET);
        $viewModel = $this->factory->create($request, 'allocations_by_month_with_share', $result, 'month');

        self::assertCount(2, $viewModel->metricColumns);
        self::assertSame('count', $viewModel->metricColumns[0]->key);
        self::assertSame('percent_of_total', $viewModel->metricColumns[1]->key);
    }

    public function testAgeGroupShowsAllBucketsByDefault(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; ++$i) {
            $rows[] = GenericAnalysisTestFixtures::enrichedRow((string) $i, 'Bucket '.$i, $i);
        }

        $result = GenericAnalysisTestFixtures::normalizedResult(rows: $rows, grandTotal: 36);
        $request = Request::create('/statistics/generic-analysis/age_group_distribution', Request::METHOD_GET);

        $viewModel = $this->factory->create($request, 'age_group_distribution', $result, 'age_group');

        self::assertCount(8, $viewModel->stackedRows);
    }

    public function testHospitalDimensionStillLimitsToTopFivePlusOtherByDefault(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; ++$i) {
            $rows[] = GenericAnalysisTestFixtures::enrichedRow((string) $i, 'Bucket '.$i, $i);
        }

        $result = GenericAnalysisTestFixtures::normalizedResult(rows: $rows, grandTotal: 36);
        $request = Request::create('/statistics/generic-analysis/allocations_by_hospital', Request::METHOD_GET);

        $viewModel = $this->factory->create($request, 'allocations_by_hospital', $result, 'hospital');

        self::assertCount(6, $viewModel->stackedRows);
        self::assertSame('Other', $viewModel->stackedRows[5]->bucketLabel);
    }

    public function testRowLimitAllShowsEveryBucketForUnboundedDimension(): void
    {
        $rows = [];
        for ($i = 1; $i <= 8; ++$i) {
            $rows[] = GenericAnalysisTestFixtures::enrichedRow((string) $i, 'Bucket '.$i, $i);
        }

        $result = GenericAnalysisTestFixtures::normalizedResult(rows: $rows, grandTotal: 36);
        $request = Request::create('/statistics/generic-analysis/allocations_by_hospital', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::TOP => 'all',
        ]);

        $viewModel = $this->factory->create($request, 'allocations_by_hospital', $result, 'hospital');

        self::assertCount(8, $viewModel->stackedRows);
    }
}
