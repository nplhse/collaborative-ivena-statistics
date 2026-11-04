<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service\Adapter;

use App\Import\Domain\Enum\AllocationRowType;
use App\Import\Infrastructure\Adapter\RuleBasedRowTypeDetector;
use App\Import\Infrastructure\Adapter\SplCsvRowReader;
use App\Import\Infrastructure\Adapter\SplCsvStreamFactory;
use App\Import\Infrastructure\Charset\EncodingDetector;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SplCsvRowReaderQuoteParsingTest extends TestCase
{
    #[DataProvider('provideQuotedIndicationCells')]
    public function testQuotedStemiRowKeepsColumnAlignment(string $pzcUndTextCsvCell, string $expectedIndicationCell): void
    {
        $path = $this->writeFixture($pzcUndTextCsvCell);
        $row = $this->readFirstAssocRow($path);

        self::assertSame('332731', $row['pzc']);
        self::assertSame($expectedIndicationCell, $row['pzc_und_text']);
        self::assertSame('', $row['manv']);
        self::assertSame('', $row['manv_id']);
        self::assertSame('07.01.2025', $row['datum_erstellungsdatum']);
        self::assertSame(AllocationRowType::ALLOCATION, new RuleBasedRowTypeDetector()->detect($row));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideQuotedIndicationCells(): iterable
    {
        yield 'unescaped inner ascii quotes' => ['"332 STEMI / "OMI""', '332 STEMI / "OMI"'];
        yield 'rfc doubled quotes' => ['"332 STEMI / ""OMI"""', '332 STEMI / "OMI"'];
        yield 'backslash escaped quotes' => ['"332 STEMI / \\"OMI\\""', '332 STEMI / \\"OMI\\"'];
        yield 'typographic quotes' => ["\"332 STEMI / \u{201C}OMI\u{201D}\"", "332 STEMI / \u{201C}OMI\u{201D}"];
    }

    public function testQuotedLineBreakStaysInsideTheField(): void
    {
        $header = 'kurzname;bemerkung;typ';
        $body = "Sana Klinikum, Offenbach;\"Hinweis:\n<br />weiter\";Klinik\nAndere Zeile;kurz;Klinik\n";
        $path = tempnam(sys_get_temp_dir(), 'ivena_csv_');
        self::assertNotFalse($path);
        file_put_contents($path, $header."\n".$body);
        $this->tempFiles[] = $path;

        $rows = iterator_to_array($this->createReader($path)->rowsAssoc(), false);

        self::assertCount(2, $rows);
        self::assertSame('Sana Klinikum, Offenbach', $rows[0]['kurzname']);
        self::assertSame("Hinweis:\n<br />weiter", $rows[0]['bemerkung']);
        self::assertSame('Klinik', $rows[0]['typ']);
        self::assertSame('Andere Zeile', $rows[1]['kurzname']);
    }

    /**
     * IVENA closure exports end records with CRLF, separate list entries inside a cell with
     * LF and quote only those cells. One cell can hold a few hundred lines.
     */
    #[DataProvider('provideLineCounts')]
    public function testLongMultiLineFieldStaysOneRecord(int $lineCount): void
    {
        $lines = [];
        for ($i = 1; $i <= $lineCount; ++$i) {
            $lines[] = 'Fachbereich '.$i;
        }
        $path = $this->writeRaw(
            "kurzname;fachbereich;typ\r\n"
            .'Klinikum Beispiel;"'.implode("\n", $lines)."\";Klinik\r\n"
            ."Klinikum Beispiel;Kardiologie;Klinik\r\n",
        );

        $rows = iterator_to_array($this->createReader($path)->rowsAssoc(), false);

        self::assertCount(2, $rows);
        self::assertSame(implode("\n", $lines), $rows[0]['fachbereich']);
        self::assertSame('Klinik', $rows[0]['typ']);
        self::assertSame('Kardiologie', $rows[1]['fachbereich']);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideLineCounts(): iterable
    {
        yield '3 lines' => [3];
        yield '21 lines, one more than the former limit' => [21];
        yield '300 lines' => [300];
    }

    public function testCrLfInsideAFieldBecomesLf(): void
    {
        $path = $this->writeRaw("a;b;c\r\n1;\"x\r\ny\";2\r\n");

        $rows = iterator_to_array($this->createReader($path)->rowsAssoc(), false);

        self::assertCount(1, $rows);
        self::assertSame("x\ny", $rows[0]['b']);
        self::assertSame('2', $rows[0]['c']);
    }

    public function testQuoteThatNeverClosesKeepsTheFollowingRecords(): void
    {
        $path = $this->writeRaw("a;b;c\n1;\"open;2\n3;4;5\n6;7;8\n");

        $rows = iterator_to_array($this->createReader($path)->rowsAssoc(), false);

        self::assertCount(3, $rows);
        self::assertSame('1', $rows[0]['a']);
        self::assertSame(['a' => '3', 'b' => '4', 'c' => '5'], $rows[1]);
        self::assertSame(['a' => '6', 'b' => '7', 'c' => '8'], $rows[2]);
    }

    public function testIso88591MultiLineFieldIsConverted(): void
    {
        $path = $this->writeRaw(
            mb_convert_encoding("Schließungseinheit;Fachgebiet;Typ\r\nITS;\"Chirurgie\nInnere Medizin\";Klinik\r\n", 'ISO-8859-1', 'UTF-8'),
        );

        $reader = new SplCsvRowReader(
            new \SplFileObject($path, 'r'),
            new EncodingDetector(),
            new SplCsvStreamFactory(new Logger('test', [new TestHandler()])),
        );
        $rows = iterator_to_array($reader->rowsAssoc(), false);

        self::assertCount(1, $rows);
        self::assertSame("Chirurgie\nInnere Medizin", $rows[0]['fachgebiet']);
        self::assertSame('Klinik', $rows[0]['typ']);
    }

    private function writeRaw(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ivena_csv_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testUnescapedInnerQuoteDoesNotSwallowTheNextRow(): void
    {
        $path = $this->writeFixture('"332 STEMI / "OMI""');
        $second = implode(';', [
            '"Next Area"',
            '"100"',
            '"plain"',
            '"08.01.2025"',
            '"11:00"',
            '""',
            '""',
        ]);
        file_put_contents($path, file_get_contents($path).$second."\n");

        $rows = iterator_to_array($this->createReader($path)->rowsAssoc(), false);

        self::assertCount(2, $rows);
        self::assertSame('Next Area', $rows[1]['versorgungsbereich']);
        self::assertSame('100', $rows[1]['pzc']);
    }

    public function testUnquotedUtf8FixtureStillReads(): void
    {
        $path = \dirname(__DIR__, 5).'/tests/Import/Fixtures/csv/utf8.csv';
        $row = $this->readFirstAssocRow($path);

        self::assertSame('ja', $row['aerztlich_begleitet']);
    }

    public function testEmptyFileIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ivena_empty_');
        self::assertNotFalse($path);
        file_put_contents($path, '');
        $this->tempFiles[] = $path;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV appears to be empty or header row could not be read.');

        $this->createReader($path);
    }

    /**
     * @return array<string, string>
     */
    private function readFirstAssocRow(string $path): array
    {
        $rows = iterator_to_array($this->createReader($path)->rowsAssoc(), false);
        self::assertNotEmpty($rows);

        return $rows[0];
    }

    private function createReader(string $path): SplCsvRowReader
    {
        return new SplCsvRowReader(
            new \SplFileObject($path, 'r'),
            new EncodingDetector(),
            new SplCsvStreamFactory(new Logger('test', [new TestHandler()])),
            'UTF-8',
        );
    }

    private function writeFixture(string $quotedPzcUndTextCell): string
    {
        $header = implode(';', [
            '"Versorgungsbereich"',
            '"PZC"',
            '"PZC und Text"',
            '"Datum (Erstellungsdatum)"',
            '"Uhrzeit (Erstellungsdatum)"',
            '"MANV"',
            '"MANV-ID"',
        ]);
        $row = implode(';', [
            '"Test Area"',
            '"332731"',
            $quotedPzcUndTextCell,
            '"07.01.2025"',
            '"10:19"',
            '""',
            '""',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'ivena_csv_');
        self::assertNotFalse($path);
        file_put_contents($path, $header."\n".$row."\n");

        $this->tempFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }
}
