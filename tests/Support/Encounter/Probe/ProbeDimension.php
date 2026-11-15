<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter\Probe;

use App\Encounter\Contract\Schema\EncounterDimensionInterface;
use App\Encounter\Contract\Schema\EncounterField;
use App\Encounter\Contract\Schema\EncounterFieldType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Field contract of the test-only schema `probe` (ADR 037, #1274): a contribution registered through
 * the container in the `test` environment (config/services/encounter_probe_test.yaml), to prove that the
 * shared import workflow selects the handler of the uploaded schema. No table, no bundle.
 */
final readonly class ProbeDimension implements EncounterDimensionInterface
{
    public const string SCHEMA = 'probe';

    public const string FINGERPRINT_RULE = 'probe:v1';

    #[\Override]
    public function schema(): string
    {
        return self::SCHEMA;
    }

    #[\Override]
    public function label(): TranslatableInterface
    {
        return new ProbeLabel();
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
            new EncounterField('record_fingerprint', EncounterFieldType::Hmac, required: true),
            new EncounterField('case_key', EncounterFieldType::Hmac, required: true),
            new EncounterField('recorded_at', EncounterFieldType::Timestamp),
        ];
    }

    #[\Override]
    public function fingerprintRules(int $version): array
    {
        $this->assertVersion($version);

        return [self::FINGERPRINT_RULE];
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
            throw new \InvalidArgumentException(sprintf('Schema probe has no version %d.', $version));
        }
    }
}
