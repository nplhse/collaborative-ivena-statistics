<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Encounter\Contract\Schema\EncounterDimensionInterface;
use App\Encounter\Contract\Schema\EncounterField;
use App\Encounter\Contract\Schema\EncounterFieldType;
use App\Encounter\Contract\Schema\EncounterKeyRules;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Field contract of the synthetic schema `inpatient_stay` v1: one row is one inpatient stay of a case.
 */
final readonly class InpatientStayDimension implements EncounterDimensionInterface
{
    public const string SCHEMA = 'inpatient_stay';

    public const string FINGERPRINT_RULE = 'inpatient-stay:v1';

    #[\Override]
    public function schema(): string
    {
        return self::SCHEMA;
    }

    #[\Override]
    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('inpatient_stay_test.schema', domain: 'inpatient_stay_test');
    }

    #[\Override]
    public function versions(): array
    {
        return [1];
    }

    #[\Override]
    public function fields(int $version): array
    {
        $this->assertVersion($version);

        return [
            new EncounterField('stay_fingerprint', EncounterFieldType::Hmac, required: true),
            new EncounterField('case_key', EncounterFieldType::Hmac, required: true),
            new EncounterField('admitted_at', EncounterFieldType::Timestamp, required: true),
            new EncounterField('discharge_diagnosis', EncounterFieldType::CatalogCode),
            new EncounterField('discharged_at', EncounterFieldType::Timestamp),
        ];
    }

    #[\Override]
    public function fingerprintRules(int $version): array
    {
        $this->assertVersion($version);

        return [self::FINGERPRINT_RULE];
    }

    /**
     * Own fingerprint over case number and admission (the profile's only anchor); the case key is the shared
     * `case:v1` rule, so a stay finds the encounter of an ED visit with the same case number.
     */
    #[\Override]
    public function keyRules(int $version): EncounterKeyRules
    {
        $this->assertVersion($version);

        return new EncounterKeyRules('stay_fingerprint', self::FINGERPRINT_RULE, [EncounterKeyRules::PART_CASE_NUMBER, EncounterKeyRules::PART_ANCHOR], 'case_key', 'case:v1');
    }

    /**
     * @return list<string>
     */
    public static function header(): array
    {
        return array_map(static fn (EncounterField $field): string => $field->name, new self()->fields(1));
    }

    private function assertVersion(int $version): void
    {
        if (1 !== $version) {
            throw new \InvalidArgumentException(sprintf('Schema inpatient_stay has no version %d.', $version));
        }
    }
}
