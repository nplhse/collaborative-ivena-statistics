<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Dimension\EncounterField;

/**
 * Builds small synthetic `ed_visit` v1 files (exchange-format.md) for tests: manifest, exact header and
 * one row per visit. Visits are named by a short label; fingerprint and case key are SHA-256 hex values
 * of the label, so the same label gives the same visit. Never real data.
 */
final class EdVisitFileBuilder
{
    public const string KEY_CHECK = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var array<string, string> */
    private array $manifest = [
        'schema' => 'ed_visit',
        'schema_version' => '1',
        'profile' => 'orbis-mts',
        'profile_version' => '1',
        'tool' => '0.3.0',
        'key_check' => self::KEY_CHECK,
        'fingerprint' => 'ed-visit:v1',
    ];

    /** @var list<array<string, string>> */
    private array $rows = [];

    private ?int $declaredRows = null;

    /** @var list<string>|null */
    private ?array $header = null;

    public static function create(): self
    {
        return new self();
    }

    /**
     * @return list<string>
     */
    public static function headerV1(): array
    {
        return array_map(static fn (EncounterField $field): string => $field->name, new EdVisitDimension()->fields(1));
    }

    public static function fingerprint(string $visit): string
    {
        return hash('sha256', 'visit|'.$visit);
    }

    public static function caseKey(string $case): string
    {
        return hash('sha256', 'case|'.$case);
    }

    /**
     * Adds a visit anchored on the first assessment contact at $anchor (`YYYY-MM-DDTHH:MM`).
     *
     * @param array<string, string> $overrides cell values by field name
     */
    public function visit(string $visit, string $anchor, ?string $case = null, array $overrides = []): self
    {
        $this->rows[] = array_merge([
            'visit_fingerprint' => self::fingerprint($visit),
            'case_key' => self::caseKey($case ?? $visit),
            'age' => '54',
            'sex' => 'F',
            'arrival_mode' => 'ambulance',
            'first_assessment_contact_at' => $anchor,
            'mts_category' => 'yellow',
            'disposition' => 'admitted',
            'care_level' => 'ward',
            'vitals_reference' => 'documented_initial',
            'heart_rate' => '88',
        ], $overrides);

        return $this;
    }

    public function manifest(string $key, ?string $value): self
    {
        if (null === $value) {
            unset($this->manifest[$key]);
        } else {
            $this->manifest[$key] = $value;
        }

        return $this;
    }

    public function declaredRows(int $rows): self
    {
        $this->declaredRows = $rows;

        return $this;
    }

    /**
     * @param list<string> $header
     */
    public function header(array $header): self
    {
        $this->header = $header;

        return $this;
    }

    public function build(): string
    {
        $header = $this->header ?? self::headerV1();
        $manifest = $this->manifest + ['rows' => (string) ($this->declaredRows ?? \count($this->rows))];
        $pairs = array_map(static fn (string $key, string $value): string => $key.'='.$value, array_keys($manifest), $manifest);

        $lines = ['#encounter-exchange;'.implode(';', $pairs), implode(';', $header)];
        foreach ($this->rows as $row) {
            $lines[] = implode(';', array_map(static fn (string $name): string => $row[$name] ?? '', $header));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A raw vendor export: semicolon-separated, quoted, with identifying columns and no manifest
     * (made-up values in the layout of an ORBIS MTS export).
     */
    public static function rawVendorExport(): string
    {
        return implode("\r\n", [
            '"Fallnummer";"Patientenname";"Geburtsdatum";"Alter";"Geschlecht";"Aufnahme ZNA";"MTS Einstufung";',
            '"0001234567";"Muster, Erika";"01.02.1970";"54";"W";"01.10.2026  08:15:00";"gelb";',
        ])."\r\n";
    }
}
