<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Import;

use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Application\Import\EncounterFileReader;
use App\Encounter\Application\Import\EncounterFileRejectedException;
use App\Encounter\Application\Import\EncounterFileValidator;
use App\Encounter\Application\Import\IdentifyingContentDetector;
use App\Encounter\Domain\Enum\EncounterFileRejection;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * File-level checks: every finding rejects the whole file (ADR 025, #1048).
 */
final class EncounterFileValidatorTest extends TestCase
{
    public function testValidFileReturnsManifestAndFieldsOfItsVersion(): void
    {
        $content = EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->visit('b', '2026-10-02T09:30')
            ->build();

        [$manifest, $fields] = $this->validate($content);

        self::assertSame('ed_visit', $manifest->schema);
        self::assertSame(1, $manifest->schemaVersion);
        self::assertSame('orbis-mts', $manifest->profile);
        self::assertSame('1.0.0', $manifest->toolVersion);
        self::assertSame(EdVisitFileBuilder::KEY_CHECK, $manifest->keyCheck);
        self::assertSame(2, $manifest->rows);
        self::assertCount(\count(EdVisitFileBuilder::headerV1()), $fields);
    }

    public function testCrlfLineEndingsAndByteOrderMarkAreAccepted(): void
    {
        $content = "\xEF\xBB\xBF".str_replace("\n", "\r\n", EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build());

        [$manifest] = $this->validate($content);

        self::assertSame(1, $manifest->rows);
    }

    public function testNewerToolVersionIsAccepted(): void
    {
        [$manifest] = $this->validate(EdVisitFileBuilder::create()->manifest('tool', '1.10.0')->visit('a', '2026-10-01T08:15')->build());

        self::assertSame('1.10.0', $manifest->toolVersion);
    }

    /**
     * @return iterable<string, array{string, EncounterFileRejection}>
     */
    public static function rejectedFiles(): iterable
    {
        $valid = static fn (): EdVisitFileBuilder => EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15');
        $header = EdVisitFileBuilder::headerV1();

        yield 'raw vendor export without manifest' => [EdVisitFileBuilder::rawVendorExport(), EncounterFileRejection::RawExport];
        yield 'canonical header without manifest line' => [implode(';', $header)."\n", EncounterFileRejection::RawExport];
        yield 'identifying source column behind a manifest' => [$valid()->header([...$header, 'Patientenname'])->build(), EncounterFileRejection::RawExport];
        yield 'date in a non-date field' => [$valid()->visit('b', '2026-10-01T09:00', overrides: ['age' => '01.02.1970'])->build(), EncounterFileRejection::IdentifyingContent];
        yield 'name in a code field' => [$valid()->visit('b', '2026-10-01T09:00', overrides: ['mts_diagram' => 'Muster, Erika'])->build(), EncounterFileRejection::IdentifyingContent];
        yield 'title and name in a code field' => [$valid()->visit('b', '2026-10-01T09:00', overrides: ['mts_indicator' => 'Dr. Muster'])->build(), EncounterFileRejection::IdentifyingContent];
        yield 'long free text' => [$valid()->visit('b', '2026-10-01T09:00', overrides: ['mts_diagram' => str_repeat('Schmerzen ', 7)])->build(), EncounterFileRejection::IdentifyingContent];
        yield 'not UTF-8' => [$valid()->visit('b', '2026-10-01T09:00', overrides: ['mts_diagram' => "Bauch\xFC"])->build(), EncounterFileRejection::EncodingInvalid];
        yield 'manifest without key check' => [$valid()->manifest('key_check', null)->build(), EncounterFileRejection::ManifestInvalid];
        yield 'manifest with malformed key check' => [$valid()->manifest('key_check', 'xyz')->build(), EncounterFileRejection::ManifestInvalid];
        yield 'manifest key twice' => [str_replace(';rows=', ';schema=ed_visit;rows=', $valid()->build()), EncounterFileRejection::ManifestInvalid];
        yield 'manifest part without value' => [str_replace(';rows=', ';broken;rows=', $valid()->build()), EncounterFileRejection::ManifestInvalid];
        yield 'rows not a number' => [$valid()->manifest('rows', 'many')->build(), EncounterFileRejection::ManifestInvalid];
        yield 'malformed tool version' => [$valid()->manifest('tool', 'latest')->build(), EncounterFileRejection::ManifestInvalid];
        yield 'unknown schema' => [$valid()->manifest('schema', 'inpatient_stay')->build(), EncounterFileRejection::SchemaUnsupported];
        yield 'unknown schema version' => [$valid()->manifest('schema_version', '2')->build(), EncounterFileRejection::SchemaUnsupported];
        yield 'malformed profile' => [$valid()->manifest('profile', 'ORBIS MTS')->build(), EncounterFileRejection::ProfileUnsupported];
        yield 'tool below minimum version' => [$valid()->manifest('tool', '0.9.9')->build(), EncounterFileRejection::ToolVersionTooOld];
        yield 'unknown fingerprint rule' => [$valid()->manifest('fingerprint', 'ed-visit:v2')->build(), EncounterFileRejection::FingerprintRuleUnsupported];
        yield 'missing column' => [$valid()->header(\array_slice($header, 0, -1))->build(), EncounterFileRejection::HeaderMismatch];
        yield 'columns in another order' => [$valid()->header([$header[1], $header[0], ...\array_slice($header, 2)])->build(), EncounterFileRejection::HeaderMismatch];
        yield 'extra harmless column' => [$valid()->header([...$header, 'extra'])->build(), EncounterFileRejection::HeaderMismatch];
        yield 'more rows than declared' => [$valid()->visit('b', '2026-10-01T09:00')->declaredRows(1)->build(), EncounterFileRejection::RowCountMismatch];
    }

    #[DataProvider('rejectedFiles')]
    public function testFileIsRejectedAsAWhole(string $content, EncounterFileRejection $expected): void
    {
        try {
            $this->validate($content);
            self::fail('The file was accepted.');
        } catch (EncounterFileRejectedException $e) {
            self::assertSame($expected, $e->reason);
            self::assertStringNotContainsString('Muster', $e->getMessage());
        }
    }

    public function testSchemaMustMatchTheSchemaChosenAtUpload(): void
    {
        $this->expectExceptionObject(new EncounterFileRejectedException(EncounterFileRejection::SchemaUnsupported));

        $this->validate(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'inpatient_stay');
    }

    public function testIdentifyingContentIsFoundEvenWhenTheManifestIsAlsoWrong(): void
    {
        $content = EdVisitFileBuilder::create()
            ->manifest('tool', '0.1.0')
            ->visit('a', '2026-10-01T08:15', overrides: ['age' => '1970-02-01'])
            ->build();

        $this->expectExceptionObject(new EncounterFileRejectedException(EncounterFileRejection::IdentifyingContent));

        $this->validate($content);
    }

    public function testTimestampsInTimestampFieldsAreNoFinding(): void
    {
        [$manifest] = $this->validate(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15', overrides: ['ed_admission_at' => '2026-10-01T08:10'])->build());

        self::assertSame(1, $manifest->rows);
    }

    /**
     * @return array{0: \App\Encounter\Application\Import\EncounterManifest, 1: list<\App\Encounter\Application\Dimension\EncounterField>}
     */
    private function validate(string $content, string $schema = 'ed_visit'): array
    {
        $validator = new EncounterFileValidator(
            new EncounterDimensionRegistry([new EdVisitDimension()]),
            new IdentifyingContentDetector(),
            '1.0.0',
        );

        return $validator->validate(new EncounterFileReader()->read($content), $schema);
    }
}
