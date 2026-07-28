<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Analysis;

use App\Import\Application\Analysis\DTO\RejectAnalysisGroup;
use App\Import\Application\Analysis\DTO\RejectAnalysisResult;
use App\Import\Infrastructure\Analysis\Export\CsvRejectAnalysisExporter;
use PHPUnit\Framework\TestCase;

final class CsvRejectAnalysisExporterTest extends TestCase
{
    public function testExportEscapesSpecialCharactersAndLongJson(): void
    {
        $longRow = str_repeat('äöü', 600);
        $group = new RejectAnalysisGroup(
            count: 2,
            field: 'speciality',
            rejectedValue: 'Value, with "quotes"',
            reason: 'REF_NOT_FOUND | field=speciality',
            exampleFile: 'var/imports/file.csv',
            exampleLine: '42',
            suggestedTransformerHint: "Add mapping/normalizer for field 'speciality'",
            exampleRawRow: '{"notes":"'.$longRow.'"}',
        );

        $path = sys_get_temp_dir().'/reject-analysis-'.bin2hex(random_bytes(4)).'.csv';
        $exporter = new CsvRejectAnalysisExporter();
        $exporter->export(new RejectAnalysisResult(2, [$group]), $path);

        try {
            $content = file_get_contents($path);
            self::assertNotFalse($content);
            self::assertStringContainsString('count,field,rejected_value,reason', $content);
            self::assertStringContainsString('"Value, with ""quotes"""', $content);
            self::assertStringContainsString('example_raw_row', $content);
        } finally {
            @unlink($path);
        }
    }

    public function testExportWritesHeaderOnlyForEmptyGroups(): void
    {
        $path = sys_get_temp_dir().'/reject-analysis-empty-'.bin2hex(random_bytes(4)).'.csv';
        $exporter = new CsvRejectAnalysisExporter();
        $exporter->export(new RejectAnalysisResult(0, []), $path);

        try {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            self::assertCount(1, $lines);
            self::assertSame(
                'count,field,rejected_value,reason,example_file,example_line,suggested_transformer_hint,example_raw_row',
                $lines[0],
            );
        } finally {
            @unlink($path);
        }
    }

    public function testExportNeutralizesFormulaInjectionInStringFields(): void
    {
        $group = new RejectAnalysisGroup(
            count: 1,
            field: 'indication',
            rejectedValue: '=CMD|"/C calc"!A0',
            reason: '-bad',
            exampleFile: '@file.csv',
            exampleLine: '9',
            suggestedTransformerHint: '+hint',
            exampleRawRow: '{"x":1}',
        );

        $path = sys_get_temp_dir().'/reject-analysis-formula-'.bin2hex(random_bytes(4)).'.csv';
        $exporter = new CsvRejectAnalysisExporter();
        $exporter->export(new RejectAnalysisResult(1, [$group]), $path);

        try {
            $content = file_get_contents($path);
            self::assertNotFalse($content);
            self::assertStringContainsString("\"'=CMD|\"\"/C calc\"\"!A0\"", $content);
            self::assertStringContainsString("'-bad,", $content);
            self::assertStringContainsString("'@file.csv,", $content);
            self::assertStringContainsString("'+hint,", $content);
            self::assertStringContainsString('"{""x"":1}"', $content);
        } finally {
            @unlink($path);
        }
    }
}
