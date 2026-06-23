<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis\Export;

use App\Statistics\GenericAnalysis\Application\Export\CsvTabularExporter;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportColumn;
use App\Statistics\GenericAnalysis\Application\Export\TabularExportDocument;
use PHPUnit\Framework\TestCase;

final class CsvTabularExporterTest extends TestCase
{
    public function testExportsUtf8BomHeadersRowsAndFooter(): void
    {
        $document = new TabularExportDocument(
            headers: [
                new TabularExportColumn('row', 'Month'),
                new TabularExportColumn('count', 'Allocations'),
            ],
            rows: [
                ['Jun 2024', 12],
                ['Jul 2024', 8],
            ],
            footerRows: [
                ['Total', 20],
            ],
        );

        $csv = $this->exportToString($document);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("Month,Allocations\n", $csv);
        self::assertStringContainsString('"Jun 2024",12'."\n", $csv);
        self::assertStringContainsString('"Jul 2024",8'."\n", $csv);
        self::assertStringContainsString("Total,20\n", $csv);
    }

    public function testQuotesSpecialCharacters(): void
    {
        $document = new TabularExportDocument(
            headers: [new TabularExportColumn('label', 'Label')],
            rows: [['Zeile mit "Anführungszeichen"']],
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString('"Zeile mit ""Anführungszeichen"""', $csv);
    }

    public function testExportsEmptyRowsWithHeaderOnly(): void
    {
        $document = new TabularExportDocument(
            headers: [
                new TabularExportColumn('row', 'Month'),
                new TabularExportColumn('count', 'Allocations'),
            ],
            rows: [],
        );

        $csv = $this->exportToString($document);

        self::assertSame("\xEF\xBB\xBFMonth,Allocations\n", $csv);
    }

    public function testFormatsNullAndFloatCells(): void
    {
        $document = new TabularExportDocument(
            headers: [new TabularExportColumn('value', 'Value')],
            rows: [[null], [10.5], [33.3333333333]],
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString("Value\n", $csv);
        self::assertStringContainsString("Value\n\n", $csv);
        self::assertStringContainsString("10.5\n", $csv);
        self::assertStringContainsString("33.3333333333\n", $csv);
    }

    public function testSupportsDynamicColumnCount(): void
    {
        $document = new TabularExportDocument(
            headers: [
                new TabularExportColumn('row', 'Row'),
                new TabularExportColumn('a', 'A'),
                new TabularExportColumn('b', 'B'),
                new TabularExportColumn('c', 'C'),
            ],
            rows: [['R1', 1, 2, 3]],
        );

        $csv = $this->exportToString($document);

        self::assertStringContainsString("Row,A,B,C\n", $csv);
        self::assertStringContainsString("R1,1,2,3\n", $csv);
    }

    private function exportToString(TabularExportDocument $document): string
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        new CsvTabularExporter()->export($document, $stream);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);

        return $content;
    }
}
