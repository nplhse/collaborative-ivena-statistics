<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\UI\Http\Controller\BenchmarkComparisonPeriodViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BenchmarkComparisonPeriodViewModelFactoryTest extends KernelTestCase
{
    private BenchmarkComparisonPeriodViewModelFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(BenchmarkComparisonPeriodViewModelFactory::class);
    }

    public function testBuildsAllTimePrimaryMenuWithoutSecondaryPicker(): void
    {
        $model = $this->factory->create(
            new Request(query: [
                'scope' => 'public',
                'comparison_scope' => 'public',
                'comparison_period' => 'all_time',
            ]),
            'app_stats_benchmarking',
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime),
        );

        self::assertFalse($model->showSecondaryPicker);
        self::assertFalse($model->showNavigation);
        self::assertNull($model->previousUrl);
        self::assertNull($model->nextUrl);
        self::assertTrue($model->primaryMenu[0]['active']);
    }

    public function testBuildsYearSecondaryMenuAndNavigationUrls(): void
    {
        $model = $this->factory->create(
            new Request(query: [
                'scope' => 'public',
                'comparison_scope' => 'public',
                'comparison_period' => 'year',
                'comparison_year' => '2024',
            ]),
            'app_stats_benchmarking',
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Year,
                2024,
            ),
        );

        self::assertTrue($model->showSecondaryPicker);
        self::assertTrue($model->showNavigation);
        self::assertNotEmpty($model->secondaryMenu);
        self::assertStringContainsString('comparison_period=year', $model->secondaryMenu[0]['url']);
        self::assertStringContainsString('comparison_year=', $model->secondaryMenu[0]['url']);
    }

    public function testBuildsQuarterAndMonthSecondaryMenus(): void
    {
        $quarterModel = $this->factory->create(
            new Request(query: [
                'comparison_scope' => 'public',
                'comparison_period' => 'quarter',
                'comparison_year' => '2024',
                'comparison_quarter' => '2',
            ]),
            'app_stats_benchmarking',
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Quarter,
                2024,
                null,
                2,
            ),
        );

        self::assertCount(4, $quarterModel->secondaryMenu);
        self::assertTrue($quarterModel->secondaryMenu[1]['active']);

        $monthModel = $this->factory->create(
            new Request(query: [
                'comparison_scope' => 'public',
                'comparison_period' => 'month',
                'comparison_year' => '2024',
                'comparison_month' => '3',
            ]),
            'app_stats_benchmarking',
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Month,
                2024,
                3,
            ),
        );

        self::assertCount(12, $monthModel->secondaryMenu);
        self::assertTrue($monthModel->secondaryMenu[2]['active']);
        self::assertNotNull($monthModel->previousUrl);
        self::assertNotNull($monthModel->nextUrl);
    }
}
