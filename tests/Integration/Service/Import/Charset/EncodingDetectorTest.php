<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import\Charset;

use App\Service\Import\Charset\EncodingDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncodingDetectorTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = \dirname(__DIR__, 5).'/tests/Fixtures/csv';
    }

    #[DataProvider('cases')]
    public function testDetect(string $file, string $expected): void
    {
        $detector = new EncodingDetector();
        $detected = $detector->detectFromPath($this->fixtures.'/'.$file);
        self::assertSame($expected, $detected);
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function cases(): array
    {
        return [
            ['utf8.csv', 'UTF-8'],
            ['utf8_bom.csv', 'UTF-8'],
            ['iso8859_1.csv', 'ISO-8859-1'],
        ];
    }
}
