<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Controller\AnalysisComparisonControlsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalysisComparisonControlsFactoryTest extends KernelTestCase
{
    public function testReturnsEmptyViewModelForNonComparisonAnalysis(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(AnalysisComparisonControlsFactory::class);

        $model = $factory->build(
            new Request(),
            'allocations_by_month',
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
        );

        self::assertFalse($model->show);
        self::assertSame([], $model->scopeChoices);
    }

    public function testBuildsComparisonScopeAndPeriodChoices(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get(AnalysisComparisonControlsFactory::class);

        $model = $factory->build(
            new Request(query: ['scope' => 'public', 'period' => 'year', 'year' => '2025']),
            'allocations_comparison_over_time',
            new StatisticsFilter(
                StatisticsFilterScope::HospitalCohort,
                null,
                new HospitalCohortKey(\App\Allocation\Domain\Enum\HospitalLocation::URBAN, \App\Allocation\Domain\Enum\HospitalTier::BASIC),
                StatisticsFilterPeriod::Year,
                2025,
            ),
        );

        self::assertTrue($model->show);
        self::assertArrayHasKey('public', $model->scopeChoices);
        self::assertTrue($model->periodChoices['year']['active']);
    }
}
