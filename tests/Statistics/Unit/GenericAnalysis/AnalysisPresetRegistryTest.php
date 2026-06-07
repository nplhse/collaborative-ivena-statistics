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

    public function testMultiMetricPresetsAreRegistered(): void
    {
        $preset = $this->registry->get('allocations_by_month_with_share');

        self::assertSame(['count', 'percent_of_total'], $preset->metricKeys);
    }

    public function testNewMetricPresetsAreRegistered(): void
    {
        $transport = $this->registry->get('transport_time_by_department');
        self::assertSame('department', $transport->primaryDimensionKey);
        self::assertSame(['count', 'median_transport_time', 'p90_transport_time'], $transport->metricKeys);
        self::assertSame('median_transport_time', $transport->visualMetricKey);

        $resus = $this->registry->get('resus_by_department');
        self::assertSame(['count', 'resus_rate'], $resus->metricKeys);
        self::assertSame('resus_rate', $resus->visualMetricKey);

        $cpr = $this->registry->get('cpr_by_month');
        self::assertSame('month', $cpr->primaryDimensionKey);
        self::assertSame(['count', 'cpr_rate'], $cpr->metricKeys);
    }

    public function testExpansionPresetsAreRegistered(): void
    {
        $genderByUrgency = $this->registry->get('gender_distribution_by_urgency');
        self::assertSame('urgency', $genderByUrgency->primaryDimensionKey);
        self::assertSame('gender', $genderByUrgency->seriesDimensionKey);
        self::assertFalse($genderByUrgency->includeNullBuckets);

        $transportByMonth = $this->registry->get('transport_time_by_month');
        self::assertSame('month', $transportByMonth->primaryDimensionKey);
        self::assertSame('median_transport_time', $transportByMonth->visualMetricKey);

        $shock = $this->registry->get('shock_rate_by_department');
        self::assertSame(['count', 'shock_rate'], $shock->metricKeys);

        $ventilation = $this->registry->get('ventilation_rate_by_hour');
        self::assertSame('hour', $ventilation->primaryDimensionKey);

        $cathlab = $this->registry->get('cathlab_rate_by_speciality');
        self::assertSame('speciality', $cathlab->primaryDimensionKey);

        $pregnancy = $this->registry->get('pregnancy_rate_by_month');
        self::assertSame('pregnancy_rate', $pregnancy->visualMetricKey);

        $workAccident = $this->registry->get('work_accident_rate_by_month');
        self::assertSame('work_accident_rate', $workAccident->visualMetricKey);

        $resusRate = $this->registry->get('resus_rate_by_hour');
        self::assertSame(['count', 'resus_rate'], $resusRate->metricKeys);

        $byDepartment = $this->registry->get('allocations_by_department');
        self::assertSame('department', $byDepartment->primaryDimensionKey);

        $urgencyByDepartment = $this->registry->get('urgency_by_department');
        self::assertSame('urgency', $urgencyByDepartment->seriesDimensionKey);

        $byHospital = $this->registry->get('allocations_by_hospital');
        self::assertSame('hospital', $byHospital->primaryDimensionKey);

        $weekdayShare = $this->registry->get('allocations_by_weekday_with_share');
        self::assertSame(['count', 'percent_of_total'], $weekdayShare->metricKeys);

        $transportByUrgency = $this->registry->get('transport_time_by_urgency');
        self::assertSame('urgency', $transportByUrgency->primaryDimensionKey);
        self::assertSame('median_transport_time', $transportByUrgency->visualMetricKey);

        $withPhysician = $this->registry->get('with_physician_rate_by_month');
        self::assertSame('month', $withPhysician->primaryDimensionKey);
        self::assertSame(['count', 'with_physician_rate'], $withPhysician->metricKeys);
        self::assertSame('with_physician_rate', $withPhysician->visualMetricKey);
    }

    public function testLegacyPresetsDefaultToEmptyMetrics(): void
    {
        $preset = $this->registry->get('allocations_by_month');

        self::assertSame([], $preset->metricKeys);
    }

    public function testUnknownPresetThrows(): void
    {
        $this->expectException(UnknownAnalysisPresetException::class);

        $this->registry->get('nonexistent');
    }
}
