<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Dimension;

use App\Encounter\Contract\Schema\EncounterDimensionInterface;
use App\Encounter\Contract\Schema\EncounterField;
use App\Encounter\Contract\Schema\EncounterFieldType;
use App\Encounter\Contract\Schema\EncounterKeyRules;
use App\Encounter\Domain\Enum\CaseStatus;
use App\Encounter\Domain\Enum\Sex;
use Cois\Plugin\EdVisit\Domain\Enum\AdmissionType;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalMode;
use Cois\Plugin\EdVisit\Domain\Enum\CareLevel;
use Cois\Plugin\EdVisit\Domain\Enum\Disposition;
use Cois\Plugin\EdVisit\Domain\Enum\IsolationCode;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;
use Cois\Plugin\EdVisit\Domain\Enum\ReferralSource;
use Cois\Plugin\EdVisit\Domain\Enum\VitalsReference;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Schema `ed_visit`: one row = one ED visit (docs/04-features/encounter/exchange-format.md).
 *
 * Field contract; the encounter import (#1048) validates files against it and maps rows onto Encounter.
 *
 * @psalm-suppress UnusedClass Tagged via EncounterDimensionInterface.
 */
final readonly class EdVisitDimension implements EncounterDimensionInterface
{
    public const string SCHEMA = 'ed_visit';

    /** Visit fingerprint rule of #1045 (exchange-format.md, "Visit fingerprint ed-visit:v1"). */
    public const string FINGERPRINT_RULE_V1 = 'ed-visit:v1';

    #[\Override]
    public function schema(): string
    {
        return self::SCHEMA;
    }

    #[\Override]
    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('encounter.import.schema.ed_visit', domain: 'encounter');
    }

    #[\Override]
    public function versions(): array
    {
        return [1];
    }

    #[\Override]
    public function fingerprintRules(int $version): array
    {
        $this->assertVersion($version);

        return [self::FINGERPRINT_RULE_V1];
    }

    /**
     * `ed-visit:v1|case number|anchor source|anchor` and the shared case key `case:v1|case number`
     * (exchange-format.md).
     */
    #[\Override]
    public function keyRules(int $version): EncounterKeyRules
    {
        $this->assertVersion($version);

        return new EncounterKeyRules(
            'visit_fingerprint',
            self::FINGERPRINT_RULE_V1,
            [EncounterKeyRules::PART_CASE_NUMBER, EncounterKeyRules::PART_ANCHOR_SOURCE, EncounterKeyRules::PART_ANCHOR],
            'case_key',
            'case:v1',
        );
    }

    #[\Override]
    public function fields(int $version): array
    {
        $this->assertVersion($version);

        $timestamp = static fn (string $name): EncounterField => new EncounterField($name, EncounterFieldType::Timestamp);
        $integer = static fn (string $name, int $min, int $max): EncounterField => new EncounterField($name, EncounterFieldType::Integer, false, [], $min, $max);
        $catalog = static fn (string $name): EncounterField => new EncounterField($name, EncounterFieldType::CatalogCode);
        $flag = static fn (string $name): EncounterField => new EncounterField($name, EncounterFieldType::Flag);

        return [
            // keys
            new EncounterField('visit_fingerprint', EncounterFieldType::Hmac, true),
            new EncounterField('case_key', EncounterFieldType::Hmac, true),
            // demographics
            new EncounterField('age', EncounterFieldType::Integer, true, [], 1, 99),
            EncounterField::code('sex', Sex::class, true),
            // arrival
            EncounterField::code('arrival_mode', ArrivalMode::class),
            EncounterField::code('referral_source', ReferralSource::class),
            EncounterField::code('admission_type', AdmissionType::class),
            EncounterField::code('case_status', CaseStatus::class),
            // times; at least one of the first two is set (checked by the import)
            $timestamp('ed_admission_at'),
            $timestamp('first_assessment_contact_at'),
            $timestamp('assessment_start_at'),
            $timestamp('assessment_end_at'),
            $timestamp('physician_target_at'),
            $timestamp('first_physician_at'),
            $timestamp('treatment_end_at'),
            $timestamp('ed_discharge_at'),
            $timestamp('admin_admission_at'),
            $timestamp('admin_discharge_at'),
            $timestamp('pre_inpatient_admission_at'),
            // triage
            EncounterField::code('mts_category', MtsCategory::class),
            $catalog('mts_diagram'),
            $catalog('mts_indicator'),
            $integer('pain_score', 0, 10),
            EncounterField::code('retriage_category', MtsCategory::class),
            $catalog('retriage_diagram'),
            $catalog('retriage_indicator'),
            $timestamp('retriage_at'),
            // disposition
            EncounterField::code('disposition', Disposition::class),
            EncounterField::code('care_level', CareLevel::class),
            $catalog('admitting_department'),
            // vitals; the reference is required as soon as any value is set (checked by the import)
            EncounterField::code('vitals_reference', VitalsReference::class),
            $timestamp('vitals_measured_at'),
            $integer('bp_systolic', 40, 300),
            $integer('bp_diastolic', 20, 200),
            $integer('heart_rate', 20, 300),
            new EncounterField('temperature', EncounterFieldType::Decimal, false, [], 25.0, 45.0),
            $integer('respiratory_rate', 3, 80),
            $integer('glucose', 10, 1500),
            $integer('spo2', 30, 100),
            $integer('gcs', 3, 15),
            // flags
            $flag('work_accident'),
            $flag('tetanus_protected'),
            EncounterField::codeSet('isolation', IsolationCode::class),
        ];
    }

    private function assertVersion(int $version): void
    {
        if (1 !== $version) {
            throw new \InvalidArgumentException(sprintf('Unknown version %d of encounter schema "%s".', $version, self::SCHEMA));
        }
    }
}
