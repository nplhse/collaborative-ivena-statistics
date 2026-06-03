<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Registry;

use App\Statistics\Application\Cohort\HospitalCohortType;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalLocationProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsHospitalTierProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisDimension;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;

final class DimensionRegistry
{
    /** @var array<string, AnalysisDimension> */
    private array $dimensions = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function get(string $key): AnalysisDimension
    {
        return $this->dimensions[$key] ?? throw UnknownAnalysisDimensionException::forKey($key);
    }

    public function has(string $key): bool
    {
        return isset($this->dimensions[$key]);
    }

    /**
     * @return list<AnalysisDimension>
     */
    public function all(): array
    {
        return array_values($this->dimensions);
    }

    private function register(AnalysisDimension $dimension): void
    {
        $this->dimensions[$dimension->key] = $dimension;
    }

    private function registerDefaults(): void
    {
        $ageBucketSql = <<<'SQL'
CASE
    WHEN age IS NULL THEN 'unknown'
    WHEN age <= 18 THEN '0_18'
    WHEN age <= 29 THEN '19_29'
    WHEN age <= 39 THEN '30_39'
    WHEN age <= 49 THEN '40_49'
    WHEN age <= 59 THEN '50_59'
    WHEN age <= 69 THEN '60_69'
    WHEN age <= 79 THEN '70_79'
    WHEN age <= 89 THEN '80_89'
    WHEN age <= 99 THEN '90_99'
    ELSE 'unknown'
END
SQL;

        $this->register($this->temporalDimension('year', 'created_year', 'Year', range(2000, 2100), 'line'));
        $this->register($this->temporalDimension('quarter', 'created_quarter', 'Quarter', [1, 2, 3, 4]));
        $this->register($this->temporalDimension('month', 'created_month', 'Month', range(1, 12)));
        $this->register($this->temporalDimension('week', 'created_week', 'Calendar week', range(1, 53)));
        $this->register(new AnalysisDimension(
            key: 'weekday',
            column: 'created_weekday',
            label: 'Weekday',
            type: AnalysisDimensionType::Temporal,
            recommendedChartType: 'bar',
            fixedBuckets: [1, 2, 3, 4, 5, 6, 7],
            valueLabels: [
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
                7 => 'Sunday',
            ],
        ));
        $this->register($this->temporalDimension('hour', 'created_hour', 'Hour', range(0, 23)));

        $this->register(new AnalysisDimension(
            key: 'gender',
            column: 'gender_code',
            label: 'Gender',
            type: AnalysisDimensionType::Categorical,
            recommendedChartType: 'pie',
            valueLabelTranslationKeys: [
                AllocationStatsGenderProjectionCode::Male->value => AllocationStatsGenderProjectionCode::Male->labelTranslationKey(),
                AllocationStatsGenderProjectionCode::Female->value => AllocationStatsGenderProjectionCode::Female->labelTranslationKey(),
                AllocationStatsGenderProjectionCode::Other->value => AllocationStatsGenderProjectionCode::Other->labelTranslationKey(),
            ],
        ));

        $this->register(new AnalysisDimension(
            key: 'age',
            column: 'age',
            label: 'Age (years)',
            type: AnalysisDimensionType::Numeric,
            recommendedChartType: 'histogram',
        ));

        $this->register(new AnalysisDimension(
            key: 'age_group',
            column: 'age',
            label: 'Age group',
            type: AnalysisDimensionType::Categorical,
            recommendedChartType: 'bar',
            sqlExpression: $ageBucketSql,
            fixedBuckets: ['0_18', '19_29', '30_39', '40_49', '50_59', '60_69', '70_79', '80_89', '90_99', 'unknown'],
            valueLabels: [
                '0_18' => '0–18',
                '19_29' => '19–29',
                '30_39' => '30–39',
                '40_49' => '40–49',
                '50_59' => '50–59',
                '60_69' => '60–69',
                '70_79' => '70–79',
                '80_89' => '80–89',
                '90_99' => '90–99',
                'unknown' => 'Unknown',
            ],
            nullBucketKeys: ['unknown'],
            requiresNonNullSourceColumn: 'age',
        ));

        $this->register(new AnalysisDimension(
            key: 'urgency',
            column: 'urgency_code',
            label: 'Urgency',
            type: AnalysisDimensionType::Categorical,
            valueLabelTranslationKeys: [
                AllocationStatsUrgencyProjectionCode::Emergency->value => AllocationStatsUrgencyProjectionCode::Emergency->labelTranslationKey(),
                AllocationStatsUrgencyProjectionCode::Inpatient->value => AllocationStatsUrgencyProjectionCode::Inpatient->labelTranslationKey(),
                AllocationStatsUrgencyProjectionCode::Outpatient->value => AllocationStatsUrgencyProjectionCode::Outpatient->labelTranslationKey(),
            ],
        ));

        $categorical = static fn (string $key, string $column, string $label): AnalysisDimension => new AnalysisDimension(
            key: $key,
            column: $column,
            label: $label,
            type: AnalysisDimensionType::Categorical,
        );

        $this->register($this->hospitalCohortDimension());

        $this->register($categorical('department', 'department_id', 'Department'));
        $this->register($categorical('assignment', 'assignment_id', 'Assignment type'));
        $this->register($categorical('indication', 'indication_normalized_id', 'Indication'));
        $this->register($categorical('infection', 'infection_id', 'Infection'));
        $this->register($categorical('hospital', 'hospital_id', 'Hospital'));
        $this->register($categorical('dispatchArea', 'dispatch_area_id', 'Dispatch area'));
        $this->register($categorical('state', 'state_id', 'State'));

        $boolean = static fn (string $key, string $column, string $label): AnalysisDimension => new AnalysisDimension(
            key: $key,
            column: $column,
            label: $label,
            type: AnalysisDimensionType::Boolean,
            fixedBuckets: [1, 0],
            valueLabels: [
                1 => 'Yes',
                0 => 'No',
            ],
        );

        $this->register($boolean('resus', 'requires_resus', 'Resuscitation required'));
        $this->register($boolean('cathlab', 'requires_cathlab', 'Cath lab required'));
        $this->register($boolean('cpr', 'is_cpr', 'CPR'));
        $this->register($boolean('ventilation', 'is_ventilated', 'Ventilation'));
        $this->register($boolean('shock', 'is_shock', 'Shock'));
        $this->register($boolean('workAccident', 'is_work_accident', 'Work accident'));
        $this->register($boolean('pregnancy', 'is_pregnant', 'Pregnancy'));
    }

    /**
     * @param list<int|string> $fixedBuckets
     */
    private function temporalDimension(
        string $key,
        string $column,
        string $label,
        array $fixedBuckets,
        string $chart = 'bar',
    ): AnalysisDimension {
        return new AnalysisDimension(
            key: $key,
            column: $column,
            label: $label,
            type: AnalysisDimensionType::Temporal,
            recommendedChartType: $chart,
            fixedBuckets: $fixedBuckets,
        );
    }

    private function hospitalCohortDimension(): AnalysisDimension
    {
        $urban = AllocationStatsHospitalLocationProjectionCode::Urban->value;
        $rural = AllocationStatsHospitalLocationProjectionCode::Rural->value;
        $basic = AllocationStatsHospitalTierProjectionCode::Basic->value;
        $extended = AllocationStatsHospitalTierProjectionCode::Extended->value;
        $full = AllocationStatsHospitalTierProjectionCode::Full->value;

        $cohortSql = sprintf(
            <<<'SQL'
CASE
    WHEN hospital_location_code = %1$d AND hospital_tier_code = %2$d THEN '%5$s'
    WHEN hospital_location_code = %1$d AND hospital_tier_code IN (%3$d, %4$d) THEN '%6$s'
    WHEN hospital_location_code = %7$d AND hospital_tier_code = %2$d THEN '%8$s'
    WHEN hospital_location_code = %7$d AND hospital_tier_code = %4$d THEN '%9$s'
    ELSE NULL
END
SQL,
            $urban,
            $basic,
            $extended,
            $full,
            HospitalCohortType::UrbanBasic->value,
            HospitalCohortType::UrbanAdvanced->value,
            $rural,
            HospitalCohortType::RuralBasic->value,
            HospitalCohortType::RuralMaximum->value,
        );

        /** @var array<string, string> $cohortLabelKeys */
        $cohortLabelKeys = [];
        /** @var list<string> $cohortBuckets */
        $cohortBuckets = [];
        foreach (HospitalCohortType::cases() as $cohortType) {
            $cohortBuckets[] = $cohortType->value;
            $cohortLabelKeys[$cohortType->value] = $cohortType->labelTranslationKey();
        }

        return new AnalysisDimension(
            key: 'hospital_cohort',
            column: 'hospital_location_code',
            label: 'Hospital cohort',
            type: AnalysisDimensionType::Categorical,
            recommendedChartType: 'bar',
            sqlExpression: $cohortSql,
            fixedBuckets: $cohortBuckets,
            valueLabelTranslationKeys: $cohortLabelKeys,
        );
    }
}
