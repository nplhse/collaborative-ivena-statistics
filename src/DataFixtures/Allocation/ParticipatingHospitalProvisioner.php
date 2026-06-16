<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Domain\HospitalPermissionMask;
use App\DataFixtures\Reference\ReferenceRegistry;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ParticipatingHospitalProvisioner
{
    public function __construct(
        private ReferenceRegistry $registry,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function provision(User $foo): void
    {
        $fooHospital = $this->resolveFooHospital();
        $this->assignOwner($fooHospital, $this->ensureParticipantRole($foo));

        $participating = array_values(array_filter(
            $this->registry->participatingHospitals(),
            static fn (Hospital $hospital): bool => (int) $hospital->getId() !== (int) $fooHospital->getId(),
        ));
        if ([] === $participating) {
            throw new \RuntimeException('No participating hospitals are loaded.');
        }

        usort(
            $participating,
            static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()),
        );

        foreach ($participating as $hospital) {
            $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']])->_real();
            $this->assignOwner($hospital, $owner);
        }

        $associate = UserFactory::createOne([
            'username' => 'associate',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ])->_real();

        $grant = new HospitalAccessGrant()
            ->setHospital($fooHospital)
            ->setUser($associate)
            ->setPermissions(HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Statistics,
                HospitalPermission::Import,
                HospitalPermission::Export,
            ]))
            ->setCreatedBy($foo);

        $fooHospital->addAccessGrant($grant);
        $this->entityManager->persist($grant);
    }

    private function resolveFooHospital(): Hospital
    {
        $candidates = $this->registry->participatingHospitalsMatching(
            HospitalTier::FULL,
            HospitalLocation::URBAN,
        );
        if ([] === $candidates) {
            throw new \RuntimeException('No participating Full Urban hospital is available for the demo owner.');
        }

        usort(
            $candidates,
            static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()),
        );

        $fooHospital = $candidates[0];
        if (!$fooHospital->isParticipating()) {
            $fooHospital->setIsParticipating(true);
            $this->entityManager->persist($fooHospital);
        }

        return $fooHospital;
    }

    private function assignOwner(Hospital $hospital, User $owner): void
    {
        $hospital->setOwner($owner);
        $this->entityManager->persist($hospital);
    }

    private function ensureParticipantRole(User $user): User
    {
        $roles = $user->getRoles();
        if (!\in_array('ROLE_PARTICIPANT', $roles, true)) {
            $roles[] = 'ROLE_PARTICIPANT';
            $user->setRoles(array_values($roles));
            $this->entityManager->persist($user);
        }

        return $user;
    }
}
