<?php

declare(strict_types=1);

namespace App\Import\Application\Factory;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\Service\ClosureImporter;
use App\Import\Application\Service\ClosureRowProcessor;
use App\Import\Infrastructure\Adapter\DoctrineClosureIntervalPersister;
use Psr\Log\LoggerInterface;

final readonly class ClosureImporterFactory
{
    public function __construct(
        private ClosureRowProcessor $processor,
        private DoctrineClosureIntervalPersister $persister,
        private LoggerInterface $importLogger,
    ) {
    }

    public function create(RowReaderInterface $reader, RejectWriterInterface $rejectWriter): ClosureImporter
    {
        return new ClosureImporter(
            reader: $reader,
            processor: $this->processor,
            persister: $this->persister,
            rejectWriter: $rejectWriter,
            logger: $this->importLogger,
        );
    }
}
