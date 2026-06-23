<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsPeriodNavigation;
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
        $anchor = new \DateTimeImmutable('first day of last month 00:00:00');
        $year = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');
        $quarter = (int) ceil($month / 3);

        $quarterModel = $this->factory->create(
            new Request(query: [
                'comparison_scope' => 'public',
                'comparison_period' => 'quarter',
                'comparison_year' => (string) $year,
                'comparison_quarter' => (string) $quarter,
            ]),
            'app_stats_benchmarking',
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Quarter,
                $year,
                null,
                $quarter,
            ),
        );

        self::assertCount(4, $quarterModel->secondaryMenu);
        self::assertTrue($quarterModel->secondaryMenu[$quarter - 1]['active']);

        $monthFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Month,
            $year,
            $month,
        );

        $monthModel = $this->factory->create(
            new Request(query: [
                'comparison_scope' => 'public',
                'comparison_period' => 'month',
                'comparison_year' => (string) $year,
                'comparison_month' => (string) $month,
            ]),
            'app_stats_benchmarking',
            $monthFilter,
        );

        $navigation = self::getContainer()->get(StatisticsPeriodNavigation::class);

        self::assertCount(12, $monthModel->secondaryMenu);
        self::assertTrue($monthModel->secondaryMenu[$month - 1]['active']);
        self::assertSame($navigation->isPreviousEnabled($monthFilter), null !== $monthModel->previousUrl);
        self::assertSame($navigation->isNextEnabled($monthFilter), null !== $monthModel->nextUrl);
    }
}
