<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Doctrine;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\User\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Removes the encounter grant of a user who loses access to a hospital (ADR 028).
 *
 * Access to a hospital means ownership or a hospital access grant. It is lost when a hospital
 * access grant is deleted or moved to another user or hospital, or when the hospital gets another
 * owner, and the user keeps neither of the two. The encounter grant is removed in the same flush,
 * so it shares the transaction and the audit intent of the change that caused it. The listener runs
 * before AuditingDoctrineSubscriber (priority -100), which therefore records the deletion.
 *
 * Deleting a hospital or a user removes encounter grants through ON DELETE CASCADE. Allocation does
 * not know this listener; it only observes Allocation entities.
 *
 * @psalm-suppress UnusedClass
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 10, connection: 'default')]
final class RevokeEncounterGrantsOnHospitalAccessLoss
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $candidates = $this->collectLostAccess($uow);
        if ([] === $candidates) {
            return;
        }

        foreach ($candidates as [$user, $hospital]) {
            if ($this->stillHasAccess($em, $uow, $user, $hospital)) {
                continue;
            }

            $grant = $em->getRepository(EncounterAccessGrant::class)->findOneBy(['user' => $user, 'hospital' => $hospital]);
            if ($grant instanceof EncounterAccessGrant && !$uow->isScheduledForDelete($grant)) {
                $em->remove($grant);
            }
        }
    }

    /**
     * @return array<string, array{0: User, 1: Hospital}>
     */
    private function collectLostAccess(UnitOfWork $uow): array
    {
        $candidates = [];
        $add = static function (mixed $user, mixed $hospital) use (&$candidates): void {
            $userId = $user instanceof User ? $user->getId() : null;
            $hospitalId = $hospital instanceof Hospital ? $hospital->getId() : null;
            if ($user instanceof User && $hospital instanceof Hospital && null !== $userId && null !== $hospitalId) {
                $candidates[$userId.':'.$hospitalId] = [$user, $hospital];
            }
        };

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof HospitalAccessGrant) {
                $changeSet = $uow->getEntityChangeSet($entity);
                $add($changeSet['user'][0] ?? $entity->getUser(), $changeSet['hospital'][0] ?? $entity->getHospital());
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changeSet = $uow->getEntityChangeSet($entity);
            if ($entity instanceof Hospital && isset($changeSet['owner'])) {
                $add($changeSet['owner'][0], $entity);
            }
            if ($entity instanceof HospitalAccessGrant && (isset($changeSet['user']) || isset($changeSet['hospital']))) {
                $add($changeSet['user'][0] ?? $entity->getUser(), $changeSet['hospital'][0] ?? $entity->getHospital());
            }
        }

        return $candidates;
    }

    /**
     * State after this flush: the in-memory owner and the hospital access grants that survive it.
     */
    private function stillHasAccess(EntityManagerInterface $em, UnitOfWork $uow, User $user, Hospital $hospital): bool
    {
        if ($uow->isScheduledForDelete($hospital) || $uow->isScheduledForDelete($user)) {
            return false;
        }

        $owner = $hospital->getOwner();
        if ($owner instanceof User && $owner->getId() === $user->getId()) {
            return true;
        }

        /** @var list<HospitalAccessGrant> $stored */
        $stored = $em->createQueryBuilder()
            ->select('g')
            ->from(HospitalAccessGrant::class, 'g')
            ->andWhere('IDENTITY(g.user) = :userId')
            ->andWhere('IDENTITY(g.hospital) = :hospitalId')
            ->setParameter('userId', $user->getId(), Types::INTEGER)
            ->setParameter('hospitalId', $hospital->getId(), Types::INTEGER)
            ->getQuery()
            ->getResult();

        $grants = [...$stored, ...array_filter(
            $uow->getScheduledEntityInsertions(),
            static fn (object $entity): bool => $entity instanceof HospitalAccessGrant,
        )];

        foreach ($grants as $grant) {
            if (!$grant instanceof HospitalAccessGrant || $uow->isScheduledForDelete($grant)) {
                continue;
            }
            if ($grant->getUser()?->getId() === $user->getId() && $grant->getHospital()?->getId() === $hospital->getId()) {
                return true;
            }
        }

        return false;
    }
}
