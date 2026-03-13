<?php

namespace App\Import\Application\Factory;

use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\Contracts\RowTypeDetectorInterface;
use App\Import\Application\Service\AllocationImporter;
use App\Import\Application\Service\AllocationRowProcessorRegistry;
use Psr\Log\LoggerInterface;

final readonly class AllocationImporterFactory
{
    public function __construct(
        private RowTypeDetectorInterface $rowTypeDetector,
        private AllocationRowProcessorRegistry $processorRegistry,
        private AllocationPersisterInterface $persister,
        private LoggerInterface $importLogger,
    ) {
    }

    public function create(
        RowReaderInterface $reader,
        RejectWriterInterface $rejectWriter,
    ): AllocationImporter {
        return new AllocationImporter(
            reader: $reader,
            rowTypeDetector: $this->rowTypeDetector,
            processorRegistry: $this->processorRegistry,
            persister: $this->persister,
            rejectWriter: $rejectWriter,
            logger: $this->importLogger,
        );
    }
}
