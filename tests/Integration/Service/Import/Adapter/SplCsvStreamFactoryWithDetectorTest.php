<?php

namespace App\Tests\Csv;

use App\Service\Import\Adapter\SplCsvStreamFactory;
use App\Service\Import\Charset\EncodingDetector;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

final class SplCsvStreamFactoryWithDetectorTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__, 5).'/tests/Fixtures/csv';
    }

    #[DataProvider('provideFixtures')]
    public function testOpenUtf8WithAutoDetect(string $file, bool $expectWarning): void
    {
        $path = Path::join($this->fixturesDir, $file);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $detector = new EncodingDetector();
        $sourceEncoding = $detector->detectFromPath($path); // auto

        $factory = new SplCsvStreamFactory($logger);
        $f = $factory->openUtf8($path, $sourceEncoding, ';', '"', '\\');

        $header = $f->fgetcsv();
        self::assertSame(
            ['Straße', 'Straße', 'KHS-Versorgungsgebiet/Bezirk?', 'Ärztlich-Begleitet'],
            $header,
            "Header mismatch for fixture: $file"
        );

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
