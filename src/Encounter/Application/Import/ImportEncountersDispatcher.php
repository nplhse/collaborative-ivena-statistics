<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Application\Message\ImportEncountersMessage;
use App\Import\Application\Contracts\ImportTypeWorkflowDispatcherInterface;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Entity\ImportRun;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportType;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Registers a run of an encounter import and queues ImportEncountersMessage in one transaction, for the
 * upload, the reprocess action and `app:import:start` (via ImportStartDispatcher).
 *
 * @psalm-suppress UnusedClass Tagged via ImportTypeWorkflowDispatcherInterface.
 */
final readonly class ImportEncountersDispatcher implements ImportTypeWorkflowDispatcherInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private TokenStorageInterface $tokenStorage,
        private ImportRunLifecycle $runLifecycle,
    ) {
    }

    #[\Override]
    public static function importType(): ImportType
    {
        return ImportType::ENCOUNTER;
    }

    #[\Override]
    public function dispatch(int $importId, ImportRunTrigger $trigger, ?int $initiatedByUserId = null, ?ImportBatchRunItem $batchRunItem = null): void
    {
        $import = $this->entityManager->find(Import::class, $importId);
        if (!$import instanceof Import) {
            throw new ImportNotFoundException($importId);
        }

        if (ImportType::ENCOUNTER !== $import->getType()) {
            throw new ImportTypeMismatchException($importId, ImportType::ENCOUNTER, $import->getType());
        }

        $user = $import->getCreatedBy();
        if (!$user instanceof User) {
            throw new ImportCreatorMissingException($importId);
        }

        // The creator is the audit user of the registration; restore the previous token afterwards.
        $previousToken = $this->tokenStorage->getToken();
        $this->tokenStorage->setToken(new PostAuthenticationToken($user, 'main', $user->getRoles()));

        try {
            $this->runLifecycle->register(
                $importId,
                $trigger,
                $initiatedByUserId,
                $batchRunItem,
                function (ImportRun $run) use ($importId, $initiatedByUserId): void {
                    $this->bus->dispatch(new ImportEncountersMessage($importId, $run->getId()->toRfc4122(), $initiatedByUserId));
                },
            );
        } catch (\Throwable $e) {
            throw new DispatchException($importId, $e);
        } finally {
            $this->tokenStorage->setToken($previousToken);
        }
    }
}
