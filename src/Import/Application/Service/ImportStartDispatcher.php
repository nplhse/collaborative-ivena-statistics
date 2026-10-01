<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportWorkflowDispatcherInterface;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\UnsupportedImportTypeException;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ImportStartDispatcher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: ImportAllocationsDispatcher::class)]
        private ImportWorkflowDispatcherInterface $allocationsDispatcher,
        #[Autowire(service: ImportClosuresDispatcher::class)]
        private ImportWorkflowDispatcherInterface $closuresDispatcher,
    ) {
    }

    public function dispatch(int $importId): ImportType
    {
        $import = $this->entityManager->find(Import::class, $importId);

        if (!$import instanceof Import) {
            throw new ImportNotFoundException($importId);
        }

        $type = $import->getType();

        if (!$type instanceof ImportType) {
            throw new UnsupportedImportTypeException($importId);
        }

        match ($type) {
            ImportType::ALLOCATION => $this->allocationsDispatcher->dispatch($importId),
            ImportType::CLOSURE => $this->closuresDispatcher->dispatch($importId),
        };

        return $type;
    }
}
