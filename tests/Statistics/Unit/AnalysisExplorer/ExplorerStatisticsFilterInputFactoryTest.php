<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerStatisticsFilterInputFactory;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use PHPUnit\Framework\TestCase;

final class ExplorerStatisticsFilterInputFactoryTest extends TestCase
{
    private ExplorerStatisticsFilterInputFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ExplorerStatisticsFilterInputFactory();
    }

    public function testPublicScopeMapsToStatisticsFilterInput(): void
    {
        $input = $this->factory->fromSideFormData(new StatisticsScopePeriodFormData(
            'public',
            null,
            'all',
        ));

        self::assertSame(StatisticsFilterScope::Public->value, $input->scope);
        self::assertSame('', $input->hospital);
        self::assertSame('all', $input->period);
        self::assertTrue($input->hasScopeQueryParameter);
    }

    public function testStateScopeMapsDetailToStateField(): void
    {
        $input = $this->factory->fromSideFormData(new StatisticsScopePeriodFormData(
            'state',
            '42',
            'year',
            periodYear: 2024,
        ));

        self::assertSame(StatisticsFilterScope::State->value, $input->scope);
        self::assertSame('42', $input->state);
        self::assertSame('year', $input->period);
        self::assertSame(2024, $input->year);
    }

    public function testMyHospitalsWithoutDetailMapsToAggregateScope(): void
    {
        $input = $this->factory->fromSideFormData(new StatisticsScopePeriodFormData(
            'my_hospitals',
            null,
            'all',
        ));

        self::assertSame(StatisticsFilterScope::MyHospitals->value, $input->scope);
        self::assertSame('', $input->hospital);
    }

    public function testMyHospitalsWithDetailMapsToHospitalScope(): void
    {
        $input = $this->factory->fromSideFormData(new StatisticsScopePeriodFormData(
            'my_hospitals',
            '7',
            'all',
        ));

        self::assertSame(StatisticsFilterScope::Hospital->value, $input->scope);
        self::assertSame('7', $input->hospital);
    }
}
