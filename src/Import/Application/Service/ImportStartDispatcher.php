<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportTypeWorkflowDispatcherInterface;
use App\Import\Application\Contracts\ImportWorkflowDispatcherInterface;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\UnsupportedImportTypeException;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ImportStartDispatcher
{
    /** @var array<string, ImportTypeWorkflowDispatcherInterface> by import type value */
    private array $typeDispatchers;

    /**
     * @param iterable<ImportTypeWorkflowDispatcherInterface> $typeDispatchers dispatchers of types other contexts process
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: ImportAllocationsDispatcher::class)]
        private ImportWorkflowDispatcherInterface $allocationsDispatcher,
        #[Autowire(service: ImportClosuresDispatcher::class)]
        private ImportWorkflowDispatcherInterface $closuresDispatcher,
        #[AutowireIterator(ImportTypeWorkflowDispatcherInterface::TAG)]
        iterable $typeDispatchers = [],
    ) {
        $byType = [];
        foreach ($typeDispatchers as $dispatcher) {
            $byType[$dispatcher::importType()->value] = $dispatcher;
        }

        $this->typeDispatchers = $byType;
    }

    /**
     * Starts a new run of an existing import with the workflow of its type.
     *
     * @param int|null                $initiatedByUserId user who deliberately started the run and is told about its outcome
     * @param ImportBatchRunItem|null $batchRunItem      batch item the run belongs to (bulk reprocess)
     */
    public function dispatch(
        int $importId,
        ?int $initiatedByUserId = null,
        ImportRunTrigger $trigger = ImportRunTrigger::Console,
        ?ImportBatchRunItem $batchRunItem = null,
    ): ImportType {
        $import = $this->entityManager->find(Import::class, $importId);

        if (!$import instanceof Import) {
            throw new ImportNotFoundException($importId);
        }

        $type = $import->getType();

        if (!$type instanceof ImportType) {
            throw new UnsupportedImportTypeException($importId);
        }

        match ($type) {
            ImportType::ALLOCATION => $this->allocationsDispatcher->dispatch($importId, $trigger, $initiatedByUserId, $batchRunItem),
            ImportType::CLOSURE => $this->closuresDispatcher->dispatch($importId, $trigger, $initiatedByUserId, $batchRunItem),
            ImportType::ENCOUNTER => $this->typeDispatcher($importId, $type)->dispatch($importId, $trigger, $initiatedByUserId, $batchRunItem),
        };

        return $type;
    }

    private function typeDispatcher(int $importId, ImportType $type): ImportTypeWorkflowDispatcherInterface
    {
        return $this->typeDispatchers[$type->value] ?? throw new UnsupportedImportTypeException($importId, $type);
    }
}
