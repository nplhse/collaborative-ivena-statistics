<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

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
        self::assertCount(9, $cohort->fixedBuckets);
    }

    public function testUnknownDimensionThrows(): void
    {
        $this->expectException(UnknownAnalysisDimensionException::class);

        $this->registry->get('evil_column');
    }
}
