<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\ComparisonFilterInputFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class ComparisonFilterInputFactoryTest extends TestCase
{
    private ComparisonFilterInputFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ComparisonFilterInputFactory();
    }

    public function testBuildsPublicComparisonInput(): void
    {
        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Year,
            2025,
            3,
        );

        $input = $this->factory->fromQuery(
            new InputBag(['comparison_scope' => 'public']),
            $primaryFilter,
            'urban_basic',
        );

        self::assertSame('public', $input->scope);
        self::assertSame('year', $input->period);
        self::assertSame(2025, $input->year);
        self::assertSame(3, $input->month);
        self::assertTrue($input->hasScopeQueryParameter);
    }

    public function testBuildsStateComparisonInputFromColonSyntax(): void
    {
        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );

        $input = $this->factory->fromQuery(
            new InputBag(['comparison_scope' => 'state:7']),
            $primaryFilter,
            'urban_basic',
        );

        self::assertSame('state', $input->scope);
        self::assertSame('7', $input->state);
    }

    public function testBuildsDispatchAreaComparisonInputFromColonSyntax(): void
    {
        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );

        $input = $this->factory->fromQuery(
            new InputBag(['comparison_scope' => 'dispatch_area:11']),
            $primaryFilter,
            'urban_basic',
        );

        self::assertSame('dispatch_area', $input->scope);
        self::assertSame('11', $input->dispatchArea);
    }

    public function testFallsBackToDefaultCohortForHospitalCohortComparison(): void
    {
        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );

        $input = $this->factory->fromQuery(
            new InputBag(['comparison_scope' => 'hospital_cohort:rural_basic']),
            $primaryFilter,
            'urban_basic',
        );

        self::assertSame('hospital_cohort', $input->scope);
        self::assertSame('rural_basic', $input->cohort);
    }

    public function testUsesExplicitComparisonPeriodAndAnchors(): void
    {
        $primaryFilter = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::Year,
            2025,
        );

        $input = $this->factory->fromQuery(
            new InputBag([
                'comparison_scope' => 'public',
                'comparison_period' => 'month',
                'comparison_year' => '2024',
                'comparison_month' => '2',
            ]),
            $primaryFilter,
            'urban_basic',
        );

        self::assertSame('month', $input->period);
        self::assertSame(2024, $input->year);
        self::assertSame(2, $input->month);
    }
}
