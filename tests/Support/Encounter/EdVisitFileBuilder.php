<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Encounter\Contract\Schema\EncounterField;
use Cois\Plugin\EdVisit\Application\Dimension\EdVisitDimension;

/**
 * Builds small synthetic `ed_visit` v1 files (exchange-format.md) for tests: manifest, exact header and
 * one row per visit, on top of the schema-independent EncounterFileBuilder. Visits are named by a short
 * label; fingerprint and case key are SHA-256 hex values of the label, so the same label gives the same
 * visit. Never real data.
 */
final readonly class EdVisitFileBuilder
{
    public const string KEY_CHECK = EncounterFileBuilder::KEY_CHECK;

    private EncounterFileBuilder $file;

    private function __construct()
    {
        $this->file = EncounterFileBuilder::for(EdVisitDimension::SCHEMA, self::headerV1(), 'ed-visit:v1', 1, 'orbis-mts');
    }

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
        return EncounterFileBuilder::caseKey($case);
    }

    /**
     * Adds a visit anchored on the first assessment contact at $anchor (`YYYY-MM-DDTHH:MM`).
     *
     * @param array<string, string> $overrides cell values by field name
     */
    public function visit(string $visit, string $anchor, ?string $case = null, array $overrides = []): self
    {
        $this->file->row(array_merge([
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
        ], $overrides));

        return $this;
    }

    public function manifest(string $key, ?string $value): self
    {
        $this->file->manifest($key, $value);

        return $this;
    }

    public function declaredRows(int $rows): self
    {
        $this->file->declaredRows($rows);

        return $this;
    }

    /**
     * @param list<string> $header
     */
    public function header(array $header): self
    {
        $this->file->header($header);

        return $this;
    }

    public function build(): string
    {
        return $this->file->build();
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
