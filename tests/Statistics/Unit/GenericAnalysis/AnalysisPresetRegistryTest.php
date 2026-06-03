<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisPresetException;
use PHPUnit\Framework\TestCase;

final class AnalysisPresetRegistryTest extends TestCase
{
    private AnalysisPresetRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AnalysisPresetRegistry();
    }

    public function testResolvesAllocationsByMonthPreset(): void
    {
        $preset = $this->registry->get('allocations_by_month');

        self::assertSame('month', $preset->primaryDimensionKey);
        self::assertNull($preset->seriesDimensionKey);
    }

    public function testHospitalCohortPresets(): void
    {
        $byCohort = $this->registry->get('allocations_by_hospital_cohort');
        $urgencyByCohort = $this->registry->get('urgency_by_hospital_cohort');

        self::assertSame('hospital_cohort', $byCohort->primaryDimensionKey);
        self::assertSame('hospital_cohort', $urgencyByCohort->primaryDimensionKey);
        self::assertSame('urgency', $urgencyByCohort->seriesDimensionKey);
    }

    public function testUnknownPresetThrows(): void
    {
        $this->expectException(UnknownAnalysisPresetException::class);

        $this->registry->get('nonexistent');
    }
}
