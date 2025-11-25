<?php

namespace App\Tests\Import\Integration\Service\Adapter;

use App\Import\Infrastructure\Adapter\SplCsvRowReader;
use App\Import\Infrastructure\Adapter\SplCsvStreamFactory;
use App\Import\Infrastructure\Charset\EncodingDetector;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

final class SplCsvRowReaderAllEncodingsTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__, 5).'/tests/Import/Fixtures/csv';
    }

    #[DataProvider('provideFixtures')]
    public function testReaderHeadersAndLogs(string $file, bool $expectWarning): void
    {
        $path = Path::join($this->fixturesDir, $file);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $reader = new SplCsvRowReader(
            new \SplFileObject($path, 'r'),
            new EncodingDetector(),
            new SplCsvStreamFactory($logger),
            'auto',
            ';',
            '"',
            '\\',
        );

        self::assertSame(
            ['strasse', 'strasse_2', 'khs_versorgungsgebiet_bezirk', 'aerztlich_begleitet'],
            $reader->header(),
            "Normalized header mismatch for fixture: $file"
        );

        $rows = iterator_to_array($reader->rowsAssoc(), false);
        self::assertSame('ja', $rows[0]['aerztlich_begleitet']);

        self::assertTrue($handler->hasInfoRecords(), "Expected info log for $file");

        if ($expectWarning) {
            self::assertTrue($handler->hasWarningRecords(), "Expected warning log for $file");
        } else {
            self::assertFalse($handler->hasWarningRecords(), "Did not expect warning log for $file");
        }
    }

    /**
     * @return list<array{0:string,1:bool}>
     */
    public static function provideFixtures(): array
    {
        return [
            ['utf8.csv', false],
            ['utf8_bom.csv', false],
            ['iso8859_1.csv', false],
        ];
    }
}
