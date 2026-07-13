<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\UI\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Application\StatisticsScopeViewModelBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StatisticsScopeViewModelBuilderTest extends KernelTestCase
{
    private StatisticsScopeViewModelBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->builder = self::getContainer()->get(StatisticsScopeViewModelBuilder::class);
    }

    public function testHeadingPeriodFormatsMonthWithLocale(): void
    {
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Month,
            referenceYear: 2024,
            referenceMonth: 6,
        );

        $heading = $this->builder->headingPeriod($filter, 'de');

        self::assertStringContainsString('2024', $heading);
        self::assertNotSame('', $heading);
    }

    public function testHeadingPeriodUsesYearForYearPeriod(): void
    {
        $filter = new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::Year,
            referenceYear: 2023,
        );

        self::assertSame('2023', $this->builder->headingPeriod($filter, 'en'));
    }
}
