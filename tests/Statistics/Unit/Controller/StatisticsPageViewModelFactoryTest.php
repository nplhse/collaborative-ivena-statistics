<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatisticsPageViewModelFactoryTest extends KernelTestCase
{
    public function testBuildsPublicScopeViewModel(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsPageViewModelFactory::class);

        $model = $factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'all']),
            'app_stats_dashboard',
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );

        self::assertFalse($model->showScopeSecondaryPicker);
        self::assertSame('public', $model->scopePrimaryMenu[0]['key']);
        self::assertTrue($model->scopePrimaryMenu[0]['active']);
        self::assertNotEmpty($model->periodUrls);
    }

    public function testBuildsMonthPeriodHeading(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(StatisticsPageViewModelFactory::class);

        $model = $factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'month', 'year' => '2024', 'month' => '3']),
            'app_stats_dashboard',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Month,
                2024,
                3,
            ),
        );

        self::assertStringContainsString('2024', $model->headingPeriod);
    }
}
