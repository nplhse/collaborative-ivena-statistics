<?php

namespace App\Import\Application\Factory;

use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Infrastructure\Adapter\SplCsvRowReader;
use App\Import\Infrastructure\Adapter\SplCsvStreamFactory;
use App\Import\Infrastructure\Charset\EncodingDetector;

final readonly class RowReaderFactory
{
    public function __construct(
        private EncodingDetector $encodingDetector,
        private SplCsvStreamFactory $streamFactory,
    ) {
    }

    public function createFromCsvFile(string $filePath): RowReaderInterface
    {
        return new SplCsvRowReader(
            new \SplFileObject($filePath, 'r'),
            $this->encodingDetector,
            $this->streamFactory,
        );
    }
}
