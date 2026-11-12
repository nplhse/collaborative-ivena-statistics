<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Import;

use App\Allocation\Domain\Entity\Department;
use App\Encounter\Application\Contract\DepartmentCatalogInterface;
use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Import\EdVisitRow;
use App\Encounter\Application\Import\EdVisitRowMapper;
use App\Encounter\Application\Import\RowError;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\IsolationCode;
use App\Encounter\Domain\Enum\MtsCategory;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Domain\Enum\VitalsReference;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Row validation of `ed_visit` v1 (#1048): one row = one visit, values as in exchange-format.md.
 */
final class EdVisitRowMapperTest extends TestCase
{
    public function testValidRowIsMappedToTypedValues(): void
    {
        $row = $this->mapValid([
            'ed_admission_at' => '2026-10-01T08:10',
            'first_assessment_contact_at' => '2026-10-01T08:15',
            'arrival_mode' => 'physician_vehicle',
            'mts_category' => 'orange',
            'mts_diagram' => 'abdominal_pain_adult',
            'pain_score' => '7',
            'disposition' => 'admitted',
            'care_level' => 'icu',
            'admitting_department' => 'innere medizin',
            'vitals_reference' => 'admission_measurement',
            'vitals_measured_at' => '2026-10-01T08:20',
            'bp_systolic' => '140',
            'temperature' => '38',
            'glucose' => '1500',
            'spo2' => '30',
            'work_accident' => '1',
            'isolation' => 'mrsa,covid19',
        ]);

        self::assertSame(12, $row->line);
        self::assertSame(EdVisitFileBuilder::fingerprint('a'), $row->visitFingerprint);
        self::assertSame(54, $row->age);
        self::assertSame(Sex::Female, $row->sex);
        self::assertSame(ArrivalMode::PhysicianVehicle, $row->arrivalMode);
        self::assertSame(AnchorSource::Assessment, $row->times->anchorSource());
        self::assertSame('2026-10-01 08:15', $row->times->anchorAt()?->format('Y-m-d H:i'));
        self::assertSame('Europe/Berlin', $row->times->anchorAt()->getTimezone()->getName());
        self::assertSame(MtsCategory::Orange, $row->triage->getCategory());
        self::assertSame('abdominal_pain_adult', $row->triage->getDiagram());
        self::assertSame(7, $row->triage->getPainScore());
        self::assertSame(Disposition::Admitted, $row->disposition->getCode());
        self::assertSame(CareLevel::Icu, $row->disposition->getCareLevel());
        self::assertSame('Innere Medizin', $row->admittingDepartment?->getName());
        self::assertSame(VitalsReference::AdmissionMeasurement, $row->vitals->getReference());
        self::assertSame(140, $row->vitals->getBpSystolic());
        self::assertSame('38.0', $row->vitals->getTemperature());
        self::assertSame(1500, $row->vitals->getGlucose());
        self::assertSame(30, $row->vitals->getSpo2());
        self::assertTrue($row->workAccident);
        self::assertNull($row->tetanusProtected);
        self::assertSame([IsolationCode::Mrsa, IsolationCode::Covid19], $row->isolation);
    }

    public function testRowWithoutTimeAnchorIsSkippedNotRejected(): void
    {
        $result = $this->map(['first_assessment_contact_at' => '', 'ed_admission_at' => '']);

        self::assertTrue($result->isSkipped());
        self::assertNull($result->row);
    }

    public function testAdmissionAloneIsAnAnchor(): void
    {
        $row = $this->mapValid(['first_assessment_contact_at' => '', 'ed_admission_at' => '2026-10-01T23:59']);

        self::assertSame(AnchorSource::Admission, $row->times->anchorSource());
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function rejectedRows(): iterable
    {
        yield 'age below 1' => [['age' => '0'], ['age: out_of_range']];
        yield 'age above 99' => [['age' => '100'], ['age: out_of_range']];
        yield 'age missing' => [['age' => ''], ['age: required']];
        yield 'age with decimals' => [['age' => '54.5'], ['age: invalid_format']];
        yield 'unknown sex' => [['sex' => 'W'], ['sex: invalid_code']];
        yield 'uppercase fingerprint' => [['visit_fingerprint' => strtoupper(EdVisitFileBuilder::fingerprint('a'))], ['visit_fingerprint: invalid_format']];
        yield 'case key missing' => [['case_key' => ''], ['case_key: required']];
        yield 'unknown arrival mode' => [['arrival_mode' => 'RTW'], ['arrival_mode: invalid_code']];
        yield 'timestamp with seconds' => [['first_assessment_contact_at' => '2026-10-01T08:15:00'], ['first_assessment_contact_at: invalid_format']];
        yield 'German timestamp' => [['ed_discharge_at' => '01.10.2026 08:15'], ['ed_discharge_at: invalid_format']];
        yield 'impossible date' => [['ed_discharge_at' => '2026-02-30T08:15'], ['ed_discharge_at: invalid_format']];
        yield 'pain score above 10' => [['pain_score' => '11'], ['pain_score: out_of_range']];
        yield 'heart rate above range' => [['heart_rate' => '301'], ['heart_rate: out_of_range']];
        yield 'heart rate below range' => [['heart_rate' => '19'], ['heart_rate: out_of_range']];
        yield 'systolic below range' => [['bp_systolic' => '39'], ['bp_systolic: out_of_range']];
        yield 'diastolic above range' => [['bp_diastolic' => '201'], ['bp_diastolic: out_of_range']];
        yield 'temperature above range' => [['temperature' => '45.1'], ['temperature: out_of_range']];
        yield 'temperature below range' => [['temperature' => '24.9'], ['temperature: out_of_range']];
        yield 'temperature with two decimals' => [['temperature' => '37.25'], ['temperature: invalid_format']];
        yield 'respiratory rate above range' => [['respiratory_rate' => '81'], ['respiratory_rate: out_of_range']];
        yield 'glucose with decimals' => [['glucose' => '99.5'], ['glucose: invalid_format']];
        yield 'glucose above range' => [['glucose' => '1501'], ['glucose: out_of_range']];
        yield 'spo2 above 100' => [['spo2' => '101'], ['spo2: out_of_range']];
        yield 'gcs below 3' => [['gcs' => '2'], ['gcs: out_of_range']];
        yield 'flag no' => [['work_accident' => '0'], ['work_accident: invalid_code']];
        yield 'unknown isolation code' => [['isolation' => 'mrsa,flu'], ['isolation: invalid_code']];
        yield 'unknown department' => [['admitting_department' => 'Raumfahrtmedizin'], ['admitting_department: invalid_code']];
        yield 'care level without admission' => [['disposition' => 'home', 'care_level' => 'ward'], ['care_level: inconsistent']];
        yield 'vital without reference' => [['vitals_reference' => '', 'heart_rate' => '80'], ['vitals_reference: required']];
        yield 'measurement time with documented initial' => [['vitals_measured_at' => '2026-10-01T08:20'], ['vitals_measured_at: inconsistent']];
        yield 'two findings' => [['age' => '120', 'spo2' => '20'], ['age: out_of_range', 'spo2: out_of_range']];
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string>          $expected
     */
    #[DataProvider('rejectedRows')]
    public function testInvalidRowIsRejectedWithFieldAndCode(array $overrides, array $expected): void
    {
        $result = $this->map($overrides);

        self::assertNull($result->row);
        self::assertSame($expected, array_map(static fn (RowError $error): string => $error->describe(), $result->errors));
    }

    public function testRowWithWrongCellCountIsRejected(): void
    {
        $result = $this->mapper()->map(3, new EdVisitDimension()->fields(1), ['only', 'three', 'cells']);

        self::assertSame(['*: column_count'], array_map(static fn (RowError $error): string => $error->describe(), $result->errors));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function mapValid(array $overrides = []): EdVisitRow
    {
        $result = $this->map($overrides);
        self::assertSame([], array_map(static fn (RowError $error): string => $error->describe(), $result->errors));
        self::assertInstanceOf(EdVisitRow::class, $result->row);

        return $result->row;
    }

    /**
     * @param array<string, string> $overrides
     */
    private function map(array $overrides): \App\Encounter\Application\Import\RowMappingResult
    {
        $cells = [
            'visit_fingerprint' => EdVisitFileBuilder::fingerprint('a'),
            'case_key' => EdVisitFileBuilder::caseKey('a'),
            'age' => '54',
            'sex' => 'F',
            'first_assessment_contact_at' => '2026-10-01T08:15',
            'vitals_reference' => 'documented_initial',
            'disposition' => 'admitted',
        ];
        $cells = array_merge($cells, $overrides);

        return $this->mapper()->map(12, new EdVisitDimension()->fields(1), array_map(
            static fn (string $name): string => $cells[$name] ?? '',
            EdVisitFileBuilder::headerV1(),
        ));
    }

    private function mapper(): EdVisitRowMapper
    {
        $department = new Department();
        $department->setName('Innere Medizin');

        return new EdVisitRowMapper(new readonly class($department) implements DepartmentCatalogInterface {
            public function __construct(private Department $department)
            {
            }

            #[\Override]
            public function findByName(string $name): ?Department
            {
                return 'innere medizin' === mb_strtolower($name) ? $this->department : null;
            }
        });
    }
}
