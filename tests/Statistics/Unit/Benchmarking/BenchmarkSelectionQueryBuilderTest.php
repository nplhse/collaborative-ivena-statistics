<?php

declare(strict_types=1);

namespace Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BenchmarkSelectionQueryBuilderTest extends TestCase
{
    private BenchmarkSelectionQueryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new BenchmarkSelectionQueryBuilder();
    }

    #[Test]
    public function buildMapsPrimaryAndComparisonScopes(): void
    {
        $data = new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('state', '3', 'all'),
            new BenchmarkSelectionSideFormData('hospital_cohort', 'urban_acute', 'all_time'),
        );

        $query = $this->builder->build($data, ['gender' => 'male']);

        self::assertSame('male', $query['gender']);
        self::assertSame('state:3', $query[StatisticsQueryKeys::SCOPE]);
        self::assertSame('3', $query[StatisticsQueryKeys::STATE]);
        self::assertSame('all', $query[StatisticsQueryKeys::PERIOD]);
        self::assertSame('hospital_cohort:urban_acute', $query[StatisticsQueryKeys::COMPARISON_SCOPE]);
        self::assertSame('urban_acute', $query[StatisticsQueryKeys::COMPARISON_COHORT]);
        self::assertSame('all_time', $query[StatisticsQueryKeys::COMPARISON_PERIOD]);
    }

    #[Test]
    public function buildMapsHospitalAndPeriodDetails(): void
    {
        $data = new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('my_hospitals', '42', StatisticsFilterPeriod::Month->value, 2025, null, 6),
            new BenchmarkSelectionSideFormData('public', null, StatisticsFilterPeriod::Quarter->value, 2024, 2),
        );

        $query = $this->builder->build($data, []);

        self::assertSame(StatisticsFilterScope::Hospital->value, $query[StatisticsQueryKeys::SCOPE]);
        self::assertSame('42', $query[StatisticsQueryKeys::HOSPITAL]);
        self::assertSame(2025, $query[StatisticsQueryKeys::YEAR]);
        self::assertSame(6, $query[StatisticsQueryKeys::MONTH]);
        self::assertSame(StatisticsFilterScope::Public->value, $query[StatisticsQueryKeys::COMPARISON_SCOPE]);
        self::assertSame(2024, $query[StatisticsQueryKeys::COMPARISON_YEAR]);
        self::assertSame(2, $query[StatisticsQueryKeys::COMPARISON_QUARTER]);
    }

    #[Test]
    public function buildMapsDispatchAreaAndMyHospitalsWithoutHospitalDetail(): void
    {
        $data = new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('dispatch_area', '9', StatisticsFilterPeriod::All->value),
            new BenchmarkSelectionSideFormData('my_hospitals', null, StatisticsFilterPeriod::AllTime->value),
        );

        $query = $this->builder->build($data, ['gender' => 'female']);

        self::assertSame('female', $query['gender']);
        self::assertSame('dispatch_area:9', $query[StatisticsQueryKeys::SCOPE]);
        self::assertSame('9', $query[StatisticsQueryKeys::DISPATCH_AREA]);
        self::assertSame(StatisticsFilterScope::MyHospitals->value, $query[StatisticsQueryKeys::COMPARISON_SCOPE]);
        self::assertArrayNotHasKey(StatisticsQueryKeys::COMPARISON_HOSPITAL, $query);
    }

    #[Test]
    public function buildFallsBackToPublicForUnknownScopeGroup(): void
    {
        $data = new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('unknown_scope', null, StatisticsFilterPeriod::AllTime->value),
            new BenchmarkSelectionSideFormData('public', null, StatisticsFilterPeriod::AllTime->value),
        );

        $query = $this->builder->build($data, []);

        self::assertSame(StatisticsFilterScope::Public->value, $query[StatisticsQueryKeys::SCOPE]);
    }
}
