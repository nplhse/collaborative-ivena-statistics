<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final readonly class ImportAllocationsDispatcher
{
    public function __construct(
        private ImportRepository $importRepository,
        private MessageBusInterface $bus,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function dispatch(int $importId): void
    {
        $import = $this->importRepository->find($importId);

        if (!$import instanceof Import) {
            throw new ImportNotFoundException($importId);
        }

        $user = $import->getCreatedBy();

        if (!$user instanceof \App\User\Domain\Entity\User) {
            throw new ImportCreatorMissingException($importId);
        }

        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);

        try {
            $this->bus->dispatch(new ImportAllocationsMessage($importId));
        } catch (\Throwable $e) {
            throw new DispatchException($importId, $e);
        } finally {
            $this->tokenStorage->setToken(null);
        }
    }
}
