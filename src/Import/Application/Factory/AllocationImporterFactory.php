<?php

namespace App\Import\Application\Factory;

use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Application\Contracts\RowReaderInterface;
use App\Import\Application\Contracts\RowToDtoMapperInterface;
use App\Import\Application\Service\AllocationImporter;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class AllocationImporterFactory
{
    public function __construct(
        private ValidatorInterface $validator,
        private RowToDtoMapperInterface $mapper,
        private AllocationImportFactory $factory,
        private AllocationPersisterInterface $persister,
        private LoggerInterface $importLogger,
    ) {
    }

    public function create(
        RowReaderInterface $reader,
        RejectWriterInterface $rejectWriter,
    ): AllocationImporter {
        return new AllocationImporter(
            validator: $this->validator,
            reader: $reader,
            mapper: $this->mapper,
            factory: $this->factory,
            persister: $this->persister,
            rejectWriter: $rejectWriter,
            logger: $this->importLogger,
        );
    }
}
