<?php

declare(strict_types=1);

namespace App\Tests\Support\Encounter;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Small, explicit fixtures for encounter access tests: users, hospitals, hospital grants and
 * encounter grants. Needs a booted kernel and Foundry's Factories trait.
 */
trait EncounterTestFixtures
{
    /**
     * @param list<string> $roles
     */
    private function user(array $roles = ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA'], ?string $username = null): User
    {
        $attributes = ['roles' => $roles];
        if (null !== $username) {
            $attributes['username'] = $username;
        }

        return UserFactory::createOne($attributes);
    }

    private function admin(): User
    {
        return $this->user(['ROLE_USER', 'ROLE_ADMIN']);
    }

    private function hospital(?User $owner): Hospital
    {
        return HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => UserFactory::createOne(),
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);
    }

    private function hospitalGrant(Hospital $hospital, User $user): HospitalAccessGrant
    {
        return HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $user,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $hospital->getOwner() ?? UserFactory::createOne(),
        ]);
    }

    /**
     * @param list<EncounterGrantAction> $actions
     */
    private function encounterGrant(Hospital $hospital, User $user, array $actions): EncounterAccessGrant
    {
        $em = $this->entityManager();
        $grant = new EncounterAccessGrant(
            $em->getReference(Hospital::class, (int) $hospital->getId()),
            $em->getReference(User::class, (int) $user->getId()),
            $actions,
        );
        $em->persist($grant);
        $em->flush();

        return $grant;
    }

    private function findEncounterGrant(Hospital $hospital, User $user): ?EncounterAccessGrant
    {
        $em = $this->entityManager();
        $em->clear();

        return $em->getRepository(EncounterAccessGrant::class)->findOneBy([
            'hospital' => $hospital->getId(),
            'user' => $user->getId(),
        ]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
