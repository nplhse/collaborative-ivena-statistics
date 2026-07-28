<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Export;

use App\Shared\Application\Export\CsvStreamExportResponseFactory;
use PHPUnit\Framework\TestCase;

final class CsvStreamExportResponseFactoryTest extends TestCase
{
    public function testWriteRowWritesCsvLine(): void
    {
        $factory = new CsvStreamExportResponseFactory();
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        $factory->writeRow($stream, ['id', 'name', null, 42]);
        rewind($stream);
        $line = stream_get_contents($stream) ?: '';
        fclose($stream);

        self::assertSame("id,name,,42\n", $line);
    }

    public function testWriteRowNeutralizesFormulaInjectionInStrings(): void
    {
        $factory = new CsvStreamExportResponseFactory();
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        $factory->writeRow($stream, ['=1+1', -5, 3.5, 'safe']);
        rewind($stream);
        $line = stream_get_contents($stream) ?: '';
        fclose($stream);

        self::assertSame("'=1+1,-5,3.5,safe\n", $line);
    }
}
