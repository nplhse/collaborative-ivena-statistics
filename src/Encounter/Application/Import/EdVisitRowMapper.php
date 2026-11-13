<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Allocation\Domain\Entity\Department;
use App\Encounter\Application\Contract\DepartmentCatalogInterface;
use App\Encounter\Application\Dimension\EncounterField;
use App\Encounter\Application\Dimension\EncounterFieldType;
use App\Encounter\Domain\Entity\EncounterDisposition;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\InitialVitals;
use App\Encounter\Domain\Entity\Retriage;
use App\Encounter\Domain\Entity\Triage;
use App\Encounter\Domain\Enum\AdmissionType;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\CaseStatus;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\EncounterRowError;
use App\Encounter\Domain\Enum\IsolationCode;
use App\Encounter\Domain\Enum\MtsCategory;
use App\Encounter\Domain\Enum\ReferralSource;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Domain\Enum\VitalsReference;

/**
 * Validates one row of an `ed_visit` file against the field contract (EdVisitDimension) and maps it to
 * typed values (#1048).
 *
 * - Empty cells are null; required fields must be set.
 * - Types: lowercase hex HMAC, integers, one-decimal temperature, `YYYY-MM-DDTHH:MM` (Europe/Berlin),
 *   code lists, `1` flags. Values outside the plausibility ranges reject the row: the tool empties them
 *   before upload, so a value out of range here is an error of the file (exchange-format.md).
 * - `admitting_department` must name a department of the reference catalog.
 * - Rules across fields: a care level only with `admitted` / `transferred_external`, a vitals reference
 *   when any vital is set, a measurement time only with `admission_measurement`.
 * - A row without ED admission and without first assessment contact is skipped, not rejected.
 */
final class EdVisitRowMapper
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i';
    private const string TIMEZONE = 'Europe/Berlin';
    private const string ADMITTING_DEPARTMENT = 'admitting_department';
    private const string MTS_CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';
    private const array VITAL_FIELDS = ['bp_systolic', 'bp_diastolic', 'heart_rate', 'temperature', 'respiratory_rate', 'glucose', 'spo2', 'gcs'];

    /** @var array<string, Department|null> */
    private array $departments = [];

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private readonly DepartmentCatalogInterface $departmentCatalog,
    ) {
    }

    /**
     * @param list<EncounterField> $fields header order of the file's schema version
     * @param list<string>         $cells
     */
    public function map(int $line, array $fields, array $cells): RowMappingResult
    {
        if (\count($cells) !== \count($fields)) {
            return RowMappingResult::rejected([new RowError('*', EncounterRowError::ColumnCount)]);
        }

        $values = [];
        $errors = [];
        foreach ($fields as $index => $field) {
            $raw = $cells[$index];
            $value = $this->convert($field, '' === $raw ? null : $raw);
            if ($value instanceof EncounterRowError) {
                $errors[] = new RowError($field->name, $value);
                continue;
            }

            $values[$field->name] = $value;
        }

        if (null === ($values['ed_admission_at'] ?? null) && null === ($values['first_assessment_contact_at'] ?? null)
            && !array_any($errors, static fn (RowError $error): bool => \in_array($error->field, ['ed_admission_at', 'first_assessment_contact_at'], true))) {
            return RowMappingResult::skipped();
        }

        $errors = [...$errors, ...$this->crossFieldErrors($values)];
        if ([] !== $errors) {
            return RowMappingResult::rejected($errors);
        }

        return RowMappingResult::valid($this->row($line, $values));
    }

    /**
     * @return EncounterRowError|int|string|bool|\DateTimeImmutable|list<string>|null
     */
    private function convert(EncounterField $field, ?string $value): EncounterRowError|int|string|bool|\DateTimeImmutable|array|null
    {
        if (null === $value) {
            return $field->required ? EncounterRowError::Required : null;
        }

        return match ($field->type) {
            EncounterFieldType::Hmac => 1 === preg_match('/^[0-9a-f]{64}$/', $value) ? $value : EncounterRowError::InvalidFormat,
            EncounterFieldType::Integer => $this->integer($field, $value),
            EncounterFieldType::Decimal => $this->decimal($field, $value),
            EncounterFieldType::Timestamp => $this->timestamp($value),
            EncounterFieldType::Code => \in_array($value, $field->allowedValues, true) ? $value : EncounterRowError::InvalidCode,
            EncounterFieldType::CodeSet => $this->codeSet($field, $value),
            EncounterFieldType::CatalogCode => $this->catalogCode($field, $value),
            EncounterFieldType::Flag => '1' === $value ? true : EncounterRowError::InvalidCode,
        };
    }

    private function integer(EncounterField $field, string $value): int|EncounterRowError
    {
        if (1 !== preg_match('/^\d{1,5}$/', $value)) {
            return EncounterRowError::InvalidFormat;
        }

        $number = (int) $value;

        return $this->inRange($field, $number) ? $number : EncounterRowError::OutOfRange;
    }

    private function decimal(EncounterField $field, string $value): string|EncounterRowError
    {
        if (1 !== preg_match('/^\d{1,3}(\.\d)?$/', $value)) {
            return EncounterRowError::InvalidFormat;
        }

        $number = (float) $value;

        return $this->inRange($field, $number) ? number_format($number, 1, '.', '') : EncounterRowError::OutOfRange;
    }

    private function inRange(EncounterField $field, int|float $number): bool
    {
        return (null === $field->min || $number >= $field->min) && (null === $field->max || $number <= $field->max);
    }

    private function timestamp(string $value): \DateTimeImmutable|EncounterRowError
    {
        $parsed = \DateTimeImmutable::createFromFormat('!'.self::TIMESTAMP_FORMAT, $value, new \DateTimeZone(self::TIMEZONE));

        return false !== $parsed && $parsed->format(self::TIMESTAMP_FORMAT) === $value ? $parsed : EncounterRowError::InvalidFormat;
    }

    /**
     * @return list<string>|EncounterRowError
     */
    private function codeSet(EncounterField $field, string $value): array|EncounterRowError
    {
        $codes = array_map(trim(...), explode(',', $value));
        foreach ($codes as $code) {
            if (!\in_array($code, $field->allowedValues, true)) {
                return EncounterRowError::InvalidCode;
            }
        }

        return $codes;
    }

    private function catalogCode(EncounterField $field, string $value): string|EncounterRowError
    {
        if (mb_strlen($value) > 64 || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return EncounterRowError::InvalidFormat;
        }

        if (self::ADMITTING_DEPARTMENT !== $field->name) {
            // MTS diagram and indicator: interim slugs of the German label until the catalog is fixed (#1056).
            return 1 === preg_match(self::MTS_CODE_PATTERN, $value) ? $value : EncounterRowError::InvalidFormat;
        }

        return $this->department($value) instanceof Department ? $value : EncounterRowError::InvalidCode;
    }

    private function department(string $name): ?Department
    {
        $key = mb_strtolower($name);
        if (!\array_key_exists($key, $this->departments)) {
            $this->departments[$key] = $this->departmentCatalog->findByName($name);
        }

        return $this->departments[$key];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<RowError>
     */
    private function crossFieldErrors(array $values): array
    {
        $errors = [];

        $disposition = isset($values['disposition']) ? Disposition::tryFrom((string) $values['disposition']) : null;
        if (isset($values['care_level']) && true !== $disposition?->carriesCareLevel()) {
            $errors[] = new RowError('care_level', EncounterRowError::Inconsistent);
        }

        $anyVital = array_any(self::VITAL_FIELDS, static fn (string $name): bool => null !== ($values[$name] ?? null));
        $reference = isset($values['vitals_reference']) ? VitalsReference::tryFrom((string) $values['vitals_reference']) : null;
        if ($anyVital && !$reference instanceof VitalsReference) {
            $errors[] = new RowError('vitals_reference', EncounterRowError::Required);
        }

        if (isset($values['vitals_measured_at']) && VitalsReference::AdmissionMeasurement !== $reference) {
            $errors[] = new RowError('vitals_measured_at', EncounterRowError::Inconsistent);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $values validated values by field name
     */
    private function row(int $line, array $values): EdVisitRow
    {
        $time = static fn (string $name): ?\DateTimeImmutable => $values[$name] ?? null;
        $int = static fn (string $name): ?int => $values[$name] ?? null;
        $string = static fn (string $name): ?string => $values[$name] ?? null;
        $department = $string(self::ADMITTING_DEPARTMENT);
        /** @var list<string> $isolation */
        $isolation = $values['isolation'] ?? [];

        return new EdVisitRow(
            line: $line,
            visitFingerprint: (string) $string('visit_fingerprint'),
            caseKey: (string) $string('case_key'),
            age: (int) $int('age'),
            sex: Sex::from((string) $string('sex')),
            times: new EncounterTimes(
                edAdmissionAt: $time('ed_admission_at'),
                firstAssessmentContactAt: $time('first_assessment_contact_at'),
                assessmentStartAt: $time('assessment_start_at'),
                assessmentEndAt: $time('assessment_end_at'),
                physicianTargetAt: $time('physician_target_at'),
                firstPhysicianAt: $time('first_physician_at'),
                treatmentEndAt: $time('treatment_end_at'),
                edDischargeAt: $time('ed_discharge_at'),
                adminAdmissionAt: $time('admin_admission_at'),
                adminDischargeAt: $time('admin_discharge_at'),
                preInpatientAdmissionAt: $time('pre_inpatient_admission_at'),
            ),
            arrivalMode: $this->enum(ArrivalMode::class, $string('arrival_mode')),
            referralSource: $this->enum(ReferralSource::class, $string('referral_source')),
            admissionType: $this->enum(AdmissionType::class, $string('admission_type')),
            caseStatus: $this->enum(CaseStatus::class, $string('case_status')),
            triage: new Triage(
                category: $this->enum(MtsCategory::class, $string('mts_category')),
                diagram: $string('mts_diagram'),
                indicator: $string('mts_indicator'),
                painScore: $int('pain_score'),
            ),
            retriage: new Retriage(
                category: $this->enum(MtsCategory::class, $string('retriage_category')),
                diagram: $string('retriage_diagram'),
                indicator: $string('retriage_indicator'),
                at: $time('retriage_at'),
            ),
            disposition: new EncounterDisposition(
                code: $this->enum(Disposition::class, $string('disposition')),
                careLevel: $this->enum(CareLevel::class, $string('care_level')),
            ),
            admittingDepartment: null !== $department ? $this->department($department) : null,
            vitals: new InitialVitals(
                reference: $this->enum(VitalsReference::class, $string('vitals_reference')),
                measuredAt: $time('vitals_measured_at'),
                bpSystolic: $int('bp_systolic'),
                bpDiastolic: $int('bp_diastolic'),
                heartRate: $int('heart_rate'),
                temperature: $this->numeric($string('temperature')),
                respiratoryRate: $int('respiratory_rate'),
                glucose: $int('glucose'),
                spo2: $int('spo2'),
                gcs: $int('gcs'),
            ),
            workAccident: true === ($values['work_accident'] ?? null) ? true : null,
            tetanusProtected: true === ($values['tetanus_protected'] ?? null) ? true : null,
            isolation: $this->isolation($isolation),
        );
    }

    /**
     * @param list<string> $codes validated isolation codes
     *
     * @return list<IsolationCode>
     */
    private function isolation(array $codes): array
    {
        $isolation = [];
        foreach ($codes as $code) {
            $isolation[] = IsolationCode::from($code);
        }

        return $isolation;
    }

    /**
     * @return numeric-string|null
     */
    private function numeric(?string $value): ?string
    {
        return null !== $value && is_numeric($value) ? $value : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private function enum(string $enum, ?string $value): ?\BackedEnum
    {
        return null === $value ? null : $enum::from($value);
    }
}
