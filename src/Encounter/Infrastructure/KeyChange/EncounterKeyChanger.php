<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\KeyChange;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterKeyChangerInterface;
use App\Encounter\Application\Event\EncounterKeyChanged;
use App\Encounter\Application\KeyChange\EncounterKeyChangeInProgressException;
use App\Encounter\Application\Message\DeleteEncounterDataAfterKeyChangeMessage;
use App\Encounter\Domain\Entity\EncounterKeyState;
use App\Encounter\Domain\Enum\EncounterKeyChangeOption;
use App\Encounter\Infrastructure\Repository\EncounterKeyStateRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Changes the pseudonym key of a hospital (ADR 026, #1050). Callers check EncounterAttribute::CHANGE_KEY.
 *
 * In one transaction holding the key state lock of the import commit (so a running import either
 * commits before or fails afterwards): the hospital enters "key change in progress", the generation is
 * incremented and the active check value retired. Imports uploaded with the old generation, queued or
 * running, can no longer write (generation check before the run and in the commit).
 *
 * - keep: the existing visits stay with their generation; the change ends in the same transaction.
 * - delete: DeleteEncounterDataAfterKeyChangeMessage is queued in the same transaction (Doctrine
 *   transport); the worker deletes the old imports and then ends the change. Uploads stay refused
 *   until then.
 *
 * Audited as `encounter.key.changed` with the option; EncounterKeyChanged notifies the administrators.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of EncounterKeyChangerInterface.
 */
final readonly class EncounterKeyChanger implements EncounterKeyChangerInterface
{
    public const string AUDIT_INTENT = 'encounter.key.changed';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncounterKeyStateRepository $keyStates,
        private AuditContext $auditContext,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function change(Hospital $hospital, EncounterKeyChangeOption $option, ?User $actor): int
    {
        $hospitalId = (int) $hospital->getId();

        $generation = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($hospitalId, $option): int {
            $this->keyStates->lockForImport($hospitalId, $this->clock->now());
            $state = $this->keyStates->findOneBy(['hospital' => $hospitalId]);
            if (!$state instanceof EncounterKeyState) {
                throw new \LogicException(sprintf('Key state of hospital #%d vanished while locked.', $hospitalId));
            }
            $em->refresh($state);

            if ($state->isKeyChangeInProgress()) {
                throw new EncounterKeyChangeInProgressException($hospitalId);
            }

            $previousGeneration = $state->getKeyGeneration();
            $state->beginKeyChange();
            if (EncounterKeyChangeOption::Keep === $option) {
                $state->completeKeyChange();
            }

            $this->auditContext->beginIntent(self::AUDIT_INTENT, [
                'hospital_id' => $hospitalId,
                'option' => $option->value,
                'previous_generation' => $previousGeneration,
                'generation' => $state->getKeyGeneration(),
            ]);
            try {
                $em->flush();
            } finally {
                $this->auditContext->endIntent();
            }

            if (EncounterKeyChangeOption::Delete === $option) {
                $this->bus->dispatch(new DeleteEncounterDataAfterKeyChangeMessage($hospitalId, $state->getKeyGeneration()));
            }

            return $state->getKeyGeneration();
        });

        $this->eventDispatcher->dispatch(new EncounterKeyChanged(
            hospitalId: $hospitalId,
            hospitalName: (string) $hospital->getName(),
            keyGeneration: $generation,
            option: $option,
            actorUsername: $actor?->getUserIdentifier() ?? '',
        ));

        return $generation;
    }
}
