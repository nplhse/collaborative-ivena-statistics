<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Support;

use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use PHPUnit\Framework\TestCase;

final class EncounterFileBuilderTest extends TestCase
{
    public function testBuildsManifestHeaderAndRowsOfAnySchema(): void
    {
        $file = EncounterFileBuilder::for('probe', ['record_fingerprint', 'case_key', 'note'], 'probe:v1')
            ->row(['record_fingerprint' => 'f1', 'case_key' => EncounterFileBuilder::caseKey('A'), 'note' => 'x'])
            ->row(['record_fingerprint' => 'f2'])
            ->build();

        self::assertSame(
            '#encounter-exchange;schema=probe;schema_version=1;profile=synthetic;profile_version=1;tool=1.0.0;key_check='.EncounterFileBuilder::KEY_CHECK.";fingerprint=probe:v1;rows=2\n"
            ."record_fingerprint;case_key;note\n"
            .'f1;'.hash('sha256', 'case|A').";x\n"
            ."f2;;\n",
            $file,
        );
    }

    public function testManifestOverridesAndDeclaredRows(): void
    {
        $file = EncounterFileBuilder::for('probe', ['a'], 'probe:v1', schemaVersion: 2, profile: 'other')
            ->manifest('tool', null)
            ->manifest('key_check', 'bb')
            ->declaredRows(5)
            ->build();

        self::assertSame("#encounter-exchange;schema=probe;schema_version=2;profile=other;profile_version=1;key_check=bb;fingerprint=probe:v1;rows=5\na\n", $file);
    }

    public function testEdVisitFilesKeepTheirFormat(): void
    {
        $file = EdVisitFileBuilder::create()
            ->visit('A', '2026-10-01T08:15', 'case-1')
            ->build();
        $lines = explode("\n", $file);
        $header = EdVisitFileBuilder::headerV1();
        $cells = array_combine($header, explode(';', $lines[2]));

        self::assertSame('#encounter-exchange;schema=ed_visit;schema_version=1;profile=orbis-mts;profile_version=1;tool=1.0.0;key_check='.EdVisitFileBuilder::KEY_CHECK.';fingerprint=ed-visit:v1;rows=1', $lines[0]);
        self::assertSame(implode(';', $header), $lines[1]);
        self::assertSame(EdVisitFileBuilder::fingerprint('A'), $cells['visit_fingerprint']);
        self::assertSame(EncounterFileBuilder::caseKey('case-1'), $cells['case_key']);
        self::assertSame('2026-10-01T08:15', $cells['first_assessment_contact_at']);
        self::assertSame('', $lines[3]);
        self::assertCount(4, $lines);
    }
}
