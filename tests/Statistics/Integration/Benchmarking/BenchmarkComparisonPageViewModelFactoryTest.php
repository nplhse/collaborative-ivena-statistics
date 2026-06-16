<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\UI\Http\Controller\BenchmarkComparisonPageViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BenchmarkComparisonPageViewModelFactoryTest extends KernelTestCase
{
    public function testBuildsComparisonScopeUrlsWithPrimaryParamsPreserved(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(BenchmarkComparisonPageViewModelFactory::class);

        $model = $factory->create(
            new Request(query: [
                'scope' => 'public',
                'period' => 'all',
                'comparison_scope' => 'public',
                'comparison_period' => 'all_time',
            ]),
            'app_stats_benchmarking',
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime),
        );

        self::assertFalse($model->showScopeSecondaryPicker);
        self::assertTrue($model->scopePrimaryMenu[0]['active']);
        self::assertStringContainsString('comparison_scope=public', $model->scopePrimaryMenu[0]['url']);
        self::assertStringContainsString('scope=public', $model->scopePrimaryMenu[0]['url']);
        self::assertStringContainsString('period=all', $model->scopePrimaryMenu[0]['url']);
    }

    public function testBuildsComparisonPeriodUrlsWithComparisonKeys(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(BenchmarkComparisonPageViewModelFactory::class);

        $model = $factory->create(
            new Request(query: [
                'scope' => 'public',
                'period' => 'all',
                'comparison_scope' => 'public',
                'comparison_period' => 'year',
                'comparison_year' => '2024',
            ]),
            'app_stats_benchmarking',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Year,
                2024,
            ),
        );

        self::assertStringContainsString('comparison_period=year', $model->periodUrls['year']);
        self::assertStringContainsString('comparison_year=2024', $model->periodUrls['year']);
        self::assertStringContainsString('scope=public', $model->periodUrls['year']);
    }
}
