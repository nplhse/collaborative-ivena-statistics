<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Dimension;

use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Application\Dimension\EncounterField;
use App\Encounter\Application\Dimension\EncounterFieldType;
use PHPUnit\Framework\TestCase;

final class EncounterDimensionRegistryTest extends TestCase
{
    public function testEdVisitV1HeaderMatchesTheExchangeFormat(): void
    {
        $names = array_map(static fn (EncounterField $field): string => $field->name, new EdVisitDimension()->fields(1));

        // Order and names as in docs/04-features/encounter/exchange-format.md.
        self::assertSame([
            'visit_fingerprint', 'case_key',
            'age', 'sex',
            'arrival_mode', 'referral_source', 'admission_type', 'case_status',
            'ed_admission_at', 'first_assessment_contact_at', 'assessment_start_at', 'assessment_end_at',
            'physician_target_at', 'first_physician_at', 'treatment_end_at', 'ed_discharge_at',
            'admin_admission_at', 'admin_discharge_at', 'pre_inpatient_admission_at',
            'mts_category', 'mts_diagram', 'mts_indicator', 'pain_score',
            'retriage_category', 'retriage_diagram', 'retriage_indicator', 'retriage_at',
            'disposition', 'care_level', 'admitting_department',
            'vitals_reference', 'vitals_measured_at',
            'bp_systolic', 'bp_diastolic', 'heart_rate', 'temperature', 'respiratory_rate', 'glucose', 'spo2', 'gcs',
            'work_accident', 'tetanus_protected', 'isolation',
        ], $names);
    }

    public function testRequiredFieldsAndCodeLists(): void
    {
        $fields = [];
        foreach (new EdVisitDimension()->fields(1) as $field) {
            $fields[$field->name] = $field;
        }

        $required = array_keys(array_filter($fields, static fn (EncounterField $field): bool => $field->required));
        self::assertSame(['visit_fingerprint', 'case_key', 'age', 'sex'], $required);
        self::assertSame(['M', 'F', 'X'], $fields['sex']->allowedValues);
        self::assertSame(['ward', 'monitoring', 'icu', 'or', 'unknown'], $fields['care_level']->allowedValues);
        self::assertSame(EncounterFieldType::CodeSet, $fields['isolation']->type);
        self::assertContains('tb', $fields['isolation']->allowedValues);
        self::assertSame([1, 99], [$fields['age']->min, $fields['age']->max]);
        self::assertSame([25.0, 45.0], [$fields['temperature']->min, $fields['temperature']->max]);
    }

    public function testUnknownVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EdVisitDimension()->fields(2);
    }

    public function testRegistryFindsSchemaByName(): void
    {
        $registry = new EncounterDimensionRegistry([new EdVisitDimension()]);

        self::assertSame(['ed_visit'], $registry->schemas());
        self::assertTrue($registry->has('ed_visit'));
        self::assertFalse($registry->has('inpatient_stay'));
        self::assertInstanceOf(EdVisitDimension::class, $registry->get('ed_visit'));
    }

    public function testUnknownSchemaIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncounterDimensionRegistry([])->get('inpatient_stay');
    }

    public function testSchemaCannotBeRegisteredTwice(): void
    {
        $this->expectException(\LogicException::class);

        new EncounterDimensionRegistry([new EdVisitDimension(), new EdVisitDimension()]);
    }
}
