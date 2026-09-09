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

    public function testUnquotedUtf8FixtureStillReads(): void
    {
        $path = \dirname(__DIR__, 5).'/tests/Import/Fixtures/csv/utf8.csv';
        $row = $this->readFirstAssocRow($path);

        self::assertSame('ja', $row['aerztlich_begleitet']);
    }

    /**
     * @return array<string, string>
     */
    private function readFirstAssocRow(string $path): array
    {
        $reader = new SplCsvRowReader(
            new \SplFileObject($path, 'r'),
            new EncodingDetector(),
            new SplCsvStreamFactory(new Logger('test', [new TestHandler()])),
            'UTF-8',
        );

        $rows = iterator_to_array($reader->rowsAssoc(), false);
        self::assertNotEmpty($rows);

        return $rows[0];
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
