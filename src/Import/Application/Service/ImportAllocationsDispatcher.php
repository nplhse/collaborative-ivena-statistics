<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportWorkflowDispatcherInterface;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Repository\ImportRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final readonly class ImportAllocationsDispatcher implements ImportWorkflowDispatcherInterface
{
    public function __construct(
        private ImportRepository $importRepository,
        private MessageBusInterface $bus,
        private TokenStorageInterface $tokenStorage,
        private ImportRunLifecycle $runLifecycle,
    ) {
    }

    #[\Override]
    public function dispatch(int $importId, ImportRunTrigger $trigger, ?int $initiatedByUserId = null, ?ImportBatchRunItem $batchRunItem = null): void
    {
        $import = $this->importRepository->find($importId);

        if (!$import instanceof Import) {
            throw new ImportNotFoundException($importId);
        }

        if (ImportType::ALLOCATION !== $import->getType()) {
            throw new ImportTypeMismatchException($importId, ImportType::ALLOCATION, $import->getType());
        }

        $user = $import->getCreatedBy();

        if (!$user instanceof \App\User\Domain\Entity\User) {
            throw new ImportCreatorMissingException($importId);
        }

        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);

        try {
            // Registration and message are committed together; a failure leaves neither behind.
            $this->runLifecycle->register(
                $importId,
                $trigger,
                $initiatedByUserId,
                $batchRunItem,
                function (ImportRun $run) use ($importId, $initiatedByUserId, $batchRunItem): void {
                    $this->bus->dispatch(new ImportAllocationsMessage(
                        importId: $importId,
                        runId: $run->getId()->toRfc4122(),
                        initiatedByUserId: $initiatedByUserId,
                        batchRunId: $batchRunItem?->getRun()?->getId(),
                    ));
                },
            );
        } catch (\Throwable $e) {
            throw new DispatchException($importId, $e);
        } finally {
            $this->tokenStorage->setToken(null);
        }
    }
}
