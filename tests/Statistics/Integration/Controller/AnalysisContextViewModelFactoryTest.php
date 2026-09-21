<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Controller\AnalysisContextPeriodMode;
use App\Statistics\UI\Http\Controller\AnalysisContextViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalysisContextViewModelFactoryTest extends KernelTestCase
{
    public function testBuildsPublicContextSummaryAndPeriodChoices(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(AnalysisContextViewModelFactory::class);

        $model = $factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'year', 'year' => '2024', 'gender' => '2']),
            'app_stats_dashboard',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Year,
                2024,
            ),
            'All assignments',
            '2024',
        );

        self::assertSame('All assignments · 2024', $model->summary);
        self::assertSame('All assignments', $model->locationLabel);
        self::assertSame('public', $model->appliedScopeGroup);
        self::assertSame('year', $model->appliedPeriod);
        self::assertSame(2024, $model->appliedYear);
        self::assertArrayHasKey('all', $model->periodChoices);
        self::assertArrayHasKey('year', $model->periodChoices);
        self::assertNotEmpty($model->yearChoices);
        self::assertSame('2', $model->preservedQuery['gender'] ?? null);
        self::assertNotContains('scope', array_keys($model->preservedQuery));
        self::assertFalse($model->hidePeriod);
        self::assertFalse($model->monthOnlyPeriod);
        self::assertStringContainsString('/statistics', $model->formAction);
    }

    public function testHiddenPeriodOmitsPeriodFromSummaryAndFormKeys(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(AnalysisContextViewModelFactory::class);

        $model = $factory->create(
            new Request(query: ['scope' => 'public']),
            'app_stats_reports',
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
            'All assignments',
            '',
            AnalysisContextPeriodMode::Hidden,
        );

        self::assertSame('All assignments', $model->summary);
        self::assertTrue($model->hidePeriod);
        self::assertNotContains('period', $model->formQueryKeys);
        self::assertContains('scope', $model->formQueryKeys);
    }

    public function testDefaultsToPublicScopeForAnonymousUsers(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(AnalysisContextViewModelFactory::class);

        $model = $factory->create(
            new Request(),
            'app_stats_dashboard',
            null,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
            'All assignments',
            'Last 12 months',
        );

        self::assertSame('public', $model->defaultScopeGroup);
        self::assertSame('all', $model->defaultPeriod);
    }

    public function testMonthOnlyModeKeepsYearAndMonthKeys(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(AnalysisContextViewModelFactory::class);

        $model = $factory->create(
            new Request(query: ['scope' => 'public', 'year' => '2024', 'month' => '3']),
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
            'All assignments',
            'March 2024',
            AnalysisContextPeriodMode::MonthOnly,
        );

        self::assertTrue($model->monthOnlyPeriod);
        self::assertFalse($model->hidePeriod);
        self::assertContains('year', $model->formQueryKeys);
        self::assertContains('month', $model->formQueryKeys);
        self::assertNotContains('period', $model->formQueryKeys);
        self::assertSame('All assignments · March 2024', $model->summary);
    }

    public function testIncludesAppliedYearWhenItIsMissingFromStoredChoices(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(AnalysisContextViewModelFactory::class);

        $model = $factory->create(
            new Request(query: ['scope' => 'public', 'period' => 'year', 'year' => '1999']),
            'app_stats_dashboard',
            null,
            new StatisticsFilter(
                StatisticsFilterScope::Public,
                null,
                null,
                StatisticsFilterPeriod::Year,
                1999,
            ),
            'All assignments',
            '1999',
        );

        self::assertArrayHasKey('1999', $model->yearChoices);
        self::assertSame(1999, $model->appliedYear);
        self::assertSame('1999', $model->yearChoices['1999']);
    }
}
