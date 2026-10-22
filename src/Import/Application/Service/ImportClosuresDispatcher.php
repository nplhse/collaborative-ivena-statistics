<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportWorkflowDispatcherInterface;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Domain\Entity\User;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final readonly class ImportClosuresDispatcher implements ImportWorkflowDispatcherInterface
{
    public function __construct(
        private ImportRepository $importRepository,
        private MessageBusInterface $bus,
        private TokenStorageInterface $tokenStorage,
        private ImportRunLifecycle $runLifecycle,
    ) {
    }

    #[\Override]
    public function dispatch(int $importId, ?int $initiatedByUserId = null, ?ImportBatchRunItem $batchRunItem = null): void
    {
        $import = $this->importRepository->find($importId);

        if (!$import instanceof Import) {
            throw new ImportNotFoundException($importId);
        }

        if (ImportType::CLOSURE !== $import->getType()) {
            throw new ImportTypeMismatchException($importId, ImportType::CLOSURE, $import->getType());
        }

        $user = $import->getCreatedBy();

        if (!$user instanceof User) {
            throw new ImportCreatorMissingException($importId);
        }

        try {
            $run = $this->runLifecycle->register($importId, $initiatedByUserId, $batchRunItem);
        } catch (\Throwable $e) {
            throw new DispatchException($importId, $e);
        }

        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);

        try {
            $this->bus->dispatch(new ImportClosuresMessage(
                importId: $importId,
                runId: $run->getId()->toRfc4122(),
                initiatedByUserId: $initiatedByUserId,
                batchRunId: $batchRunItem?->getRun()?->getId(),
            ));
        } catch (\Throwable $e) {
            $this->runLifecycle->failDispatch($run);

            throw new DispatchException($importId, $e);
        } finally {
            $this->tokenStorage->setToken(null);
        }
    }
}
