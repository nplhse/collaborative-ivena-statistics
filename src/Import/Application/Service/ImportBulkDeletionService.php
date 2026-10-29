<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\DTO\ImportBulkActionResult;
use App\Import\Application\DTO\ImportBulkSkipReason;
use App\Import\Application\Message\DeleteImportsMessage;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Repository\ImportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Queues the deletion of selected imports (#720). Deleting removes result rows, projections and files and
 * can take long for large imports, so it runs in the worker (DeleteImportsMessageHandler); the request only
 * checks the selection and queues one message.
 */
final readonly class ImportBulkDeletionService
{
    public function __construct(
        private ImportRepository $importRepository,
        private MessageBusInterface $bus,
        #[Target('import')]
        private LoggerInterface $importLogger,
    ) {
    }

    /**
     * @param list<int>              $importIds selected ids, validated and de-duplicated
     * @param \Closure(Import): bool $canDelete whether the user may delete the import
     */
    public function queue(array $importIds, int $initiatedByUserId, \Closure $canDelete): ImportBulkActionResult
    {
        $found = [];
        foreach ($this->importRepository->findBy(['id' => $importIds]) as $import) {
            $found[(int) $import->getId()] = $import;
        }

        $skipped = [];
        $eligible = [];
        foreach ($importIds as $id) {
            $import = $found[$id] ?? null;
            $reason = null;
            if (null === $import || !$canDelete($import)) {
                $reason = ImportBulkSkipReason::Forbidden;
            } elseif (ImportStatus::RUNNING === $import->getStatus()) {
                $reason = ImportBulkSkipReason::Busy;
            }
            if ($reason instanceof ImportBulkSkipReason) {
                $skipped[$reason->value] = ($skipped[$reason->value] ?? 0) + 1;

                continue;
            }
            $eligible[] = $id;
        }

        if ([] === $eligible) {
            return new ImportBulkActionResult(0, $skipped);
        }

        $operationId = Uuid::v7()->toRfc4122();
        $this->bus->dispatch(new DeleteImportsMessage($operationId, $initiatedByUserId, $eligible));
        $this->importLogger->info('import.bulk_delete.queued', [
            'operation_id' => $operationId,
            'initiated_by' => $initiatedByUserId,
            'imports' => \count($eligible),
            'skipped' => $skipped,
        ]);

        return new ImportBulkActionResult(\count($eligible), $skipped);
    }
}
