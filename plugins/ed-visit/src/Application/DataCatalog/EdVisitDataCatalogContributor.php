<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\DataCatalog;

use App\Allocation\Application\DTO\DataCatalog\ValueRange;
use App\Allocation\Application\Explore\DataCatalog\CodeLabels;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogContributorInterface;
use App\Allocation\Application\Explore\DataCatalog\DataObjectDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableGroupDefinition;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Schema\EncounterField;
use Cois\Plugin\EdVisit\Application\Dimension\EdVisitDimension;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;

/**
 * The ED visit in the Explore data catalog (#1415), shown only to users who may see cases of any hospital.
 * Ranges come from the field contract of the exchange format (EdVisitDimension), so the catalog and the import
 * check the same limits. Left out on purpose: the visit uid and fingerprint, the key generation, the import,
 * the hospital (on the encounter) and the isolation codes (a JSON code set the catalog cannot describe yet).
 */
final readonly class EdVisitDataCatalogContributor implements DataCatalogContributorInterface
{
    private const string DOMAIN = 'encounter';

    public function __construct(private EdVisitDimension $dimension)
    {
    }

    #[\Override]
    public function dataObjects(): array
    {
        $mts = CodeLabels::prefixed('encounter.mts.category.', self::DOMAIN, '.name');
        $time = static fn (string $key, string $property): VariableDefinition => new VariableDefinition('ed_visit.'.$key, 'times.'.$property);

        return [
            new DataObjectDefinition('ed_visit', EdVisit::class, self::DOMAIN, 'tabler:heartbeat', [
                new VariableGroupDefinition('case', [
                    new VariableDefinition('ed_visit.encounter', 'encounter'),
                    new VariableDefinition('ed_visit.anchor_source', 'anchorSource', codeLabels: CodeLabels::prefixed('data_catalog.code.anchor_source.', self::DOMAIN)),
                    new VariableDefinition('ed_visit.case_status', 'caseStatus', codeLabels: CodeLabels::prefixed('encounter.visit.case_status.', self::DOMAIN)),
                ]),
                new VariableGroupDefinition('arrival', [
                    new VariableDefinition('ed_visit.arrival_mode', 'arrivalMode', codeLabels: CodeLabels::prefixed('encounter.review.value.mode_', self::DOMAIN)),
                    new VariableDefinition('ed_visit.arrival_route', 'arrivalRoute', codeLabels: CodeLabels::prefixed('encounter.arrival_route.', self::DOMAIN)),
                    new VariableDefinition('ed_visit.referral_source', 'referralSource', codeLabels: CodeLabels::prefixed('encounter.visit.referral.', self::DOMAIN)),
                    new VariableDefinition('ed_visit.admission_type', 'admissionType', codeLabels: CodeLabels::prefixed('data_catalog.code.admission_type.', self::DOMAIN)),
                ]),
                new VariableGroupDefinition('time', [
                    $time('ed_admission_at', 'edAdmissionAt'),
                    $time('first_assessment_contact_at', 'firstAssessmentContactAt'),
                    $time('assessment_start_at', 'assessmentStartAt'),
                    $time('assessment_end_at', 'assessmentEndAt'),
                    $time('physician_target_at', 'physicianTargetAt'),
                    $time('first_physician_at', 'firstPhysicianAt'),
                    $time('treatment_end_at', 'treatmentEndAt'),
                    $time('ed_discharge_at', 'edDischargeAt'),
                    $time('admin_admission_at', 'adminAdmissionAt'),
                    $time('admin_discharge_at', 'adminDischargeAt'),
                    $time('pre_inpatient_admission_at', 'preInpatientAdmissionAt'),
                ]),
                new VariableGroupDefinition('triage', [
                    new VariableDefinition('ed_visit.mts_category', 'triage.category', codeLabels: $mts),
                    new VariableDefinition('ed_visit.mts_diagram', 'triage.diagram'),
                    new VariableDefinition('ed_visit.mts_indicator', 'triage.indicator'),
                    new VariableDefinition('ed_visit.pain_score', 'triage.painScore', $this->range('pain_score')),
                ]),
                new VariableGroupDefinition('retriage', [
                    new VariableDefinition('ed_visit.retriage_category', 'retriage.category', codeLabels: $mts),
                    new VariableDefinition('ed_visit.retriage_diagram', 'retriage.diagram'),
                    new VariableDefinition('ed_visit.retriage_indicator', 'retriage.indicator'),
                    new VariableDefinition('ed_visit.retriage_at', 'retriage.at'),
                ]),
                new VariableGroupDefinition('disposition', [
                    new VariableDefinition('ed_visit.disposition', 'disposition.code', codeLabels: CodeLabels::prefixed('encounter.disposition.', self::DOMAIN)),
                    new VariableDefinition('ed_visit.care_level', 'disposition.careLevel', codeLabels: CodeLabels::prefixed('encounter.care_level.', self::DOMAIN)),
                    new VariableDefinition('ed_visit.admitting_department', 'admittingDepartment'),
                ]),
                new VariableGroupDefinition('vitals', [
                    new VariableDefinition('ed_visit.vitals_reference', 'vitals.reference', codeLabels: CodeLabels::prefixed('data_catalog.code.vitals_reference.', self::DOMAIN)),
                    new VariableDefinition('ed_visit.vitals_measured_at', 'vitals.measuredAt'),
                    new VariableDefinition('ed_visit.bp_systolic', 'vitals.bpSystolic', $this->range('bp_systolic', 'data_catalog.unit.mmhg')),
                    new VariableDefinition('ed_visit.bp_diastolic', 'vitals.bpDiastolic', $this->range('bp_diastolic', 'data_catalog.unit.mmhg')),
                    new VariableDefinition('ed_visit.heart_rate', 'vitals.heartRate', $this->range('heart_rate', 'data_catalog.unit.per_minute')),
                    new VariableDefinition('ed_visit.temperature', 'vitals.temperature', $this->range('temperature', 'data_catalog.unit.celsius')),
                    new VariableDefinition('ed_visit.respiratory_rate', 'vitals.respiratoryRate', $this->range('respiratory_rate', 'data_catalog.unit.per_minute')),
                    new VariableDefinition('ed_visit.glucose', 'vitals.glucose', $this->range('glucose', 'data_catalog.unit.mg_dl')),
                    new VariableDefinition('ed_visit.spo2', 'vitals.spo2', $this->range('spo2', 'data_catalog.unit.percent')),
                    new VariableDefinition('ed_visit.gcs', 'vitals.gcs', $this->range('gcs')),
                ]),
                new VariableGroupDefinition('context', [
                    new VariableDefinition('ed_visit.work_accident', 'workAccident'),
                    new VariableDefinition('ed_visit.tetanus_protected', 'tetanusProtected'),
                ]),
            ], contributedByPlugin: true, grantedAttribute: EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL),
        ];
    }

    private function range(string $field, ?string $unitKey = null): ValueRange
    {
        foreach ($this->dimension->fields(1) as $candidate) {
            if ($candidate->name === $field) {
                return $this->rangeOf($candidate, $unitKey);
            }
        }

        throw new \LogicException(sprintf('The ed_visit field contract has no field "%s".', $field));
    }

    private function rangeOf(EncounterField $field, ?string $unitKey): ValueRange
    {
        if (null === $field->min || null === $field->max) {
            throw new \LogicException(sprintf('The ed_visit field "%s" has no range.', $field->name));
        }

        return new ValueRange($field->min, $field->max, $unitKey);
    }
}
