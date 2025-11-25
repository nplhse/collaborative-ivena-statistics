<?php

namespace App\Tests\Import\Integration\Service\Adapter;

use App\Import\Infrastructure\Adapter\SplCsvStreamFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

final class SplCsvStreamFactoryTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__, 5).'/tests/Import/Fixtures/csv';
    }

    #[DataProvider('provideEncodings')]
    public function testOpenUtf8ForAllFixtures(string $file, string $sourceEncoding, bool $expectWarning): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $factory = new SplCsvStreamFactory($logger);
        $path = Path::join($this->fixturesDir, $file);

        $file = $factory->openUtf8($path, $sourceEncoding, ';', '"', '\\');

        $header = $file->fgetcsv();
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
     * @return list<array{0:string,1:string,2:bool}>
     */
    public static function provideEncodings(): array
    {
        return [
            ['utf8.csv', 'UTF-8', false],
            ['utf8_bom.csv', 'UTF-8', false],
            ['iso8859_1.csv', 'ISO-8859-1', false],
        ];
    }
}
