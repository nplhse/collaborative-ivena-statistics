<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;

final class DimensionRegistryTest extends TestCase
{
    private DimensionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new DimensionRegistry();
    }

    public function testResolvesTemporalMonthWithFixedBuckets(): void
    {
        $month = $this->registry->get('month');

        self::assertSame('created_month', $month->column);
        self::assertSame(AnalysisDimensionType::Temporal, $month->type);
        self::assertSame(range(1, 12), $month->fixedBuckets);
    }

    public function testAgeGroupUsesSqlExpressionNotRawUserColumn(): void
    {
        $ageGroup = $this->registry->get('age_group');

        self::assertNotNull($ageGroup->sqlExpression);
        self::assertStringContainsString('CASE', $ageGroup->sqlExpression);
        self::assertStringNotContainsString('100p', $ageGroup->sqlExpression);
        self::assertSame('age', $ageGroup->column);
        self::assertNotContains('100p', $ageGroup->fixedBuckets);
        self::assertArrayNotHasKey('100p', $ageGroup->valueLabels);
    }

    public function testHospitalCohortUsesCaseExpression(): void
    {
        $cohort = $this->registry->get('hospital_cohort');

        self::assertNotNull($cohort->sqlExpression);
        self::assertStringContainsString('urban_basic', $cohort->sqlExpression);
        self::assertStringContainsString('mixed_extended', $cohort->sqlExpression);
        self::assertStringContainsString('rural_full', $cohort->sqlExpression);
        self::assertCount(9, $cohort->fixedBuckets);
        self::assertSame(HospitalCohortKey::all()[0]->value(), $cohort->fixedBuckets[0]);
    }

    public function testResolvesSpecialityAndOccasionDimensions(): void
    {
        $speciality = $this->registry->get('speciality');
        $occasion = $this->registry->get('occasion');

        self::assertSame('speciality_id', $speciality->column);
        self::assertSame(AnalysisDimensionType::Categorical, $speciality->type);
        self::assertSame('occasion_id', $occasion->column);
        self::assertSame(AnalysisDimensionType::Categorical, $occasion->type);
    }

    public function testUnknownDimensionThrows(): void
    {
        $this->expectException(UnknownAnalysisDimensionException::class);

        $this->registry->get('evil_column');
    }

    public function testRegistersPhase6ProjectionCodeDimensions(): void
    {
        $transport = $this->registry->get('transport_type');
        self::assertSame('transport_type_code', $transport->column);
        self::assertSame([1, 2], $transport->fixedBuckets);

        $dayTime = $this->registry->get('day_time_bucket');
        self::assertSame('day_time_bucket_code', $dayTime->column);
        self::assertSame([2, 3, 4, 1], $dayTime->fixedBuckets);

        $shift = $this->registry->get('shift_bucket');
        self::assertSame('shift_bucket_code', $shift->column);
        self::assertSame([2, 3, 1], $shift->fixedBuckets);
    }

    public function testRegistersSecondaryIndicationAndWithPhysician(): void
    {
        $secondary = $this->registry->get('secondary_indication');
        self::assertSame('secondary_indication_normalized_id', $secondary->column);

        $withPhysician = $this->registry->get('with_physician');
        self::assertSame('is_with_physician', $withPhysician->column);
        self::assertSame(AnalysisDimensionType::Boolean, $withPhysician->type);
    }
}
