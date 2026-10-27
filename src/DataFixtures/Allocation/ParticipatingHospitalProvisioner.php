<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\DataFixtures\FixtureVolumeResolver;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ParticipatingHospitalProvisioner
{
    public function __construct(
        private FixtureHospitals $hospitals,
        private FixtureHospitalSelection $selection,
        private FixtureVolumeResolver $volumeResolver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function provision(User $foo): void
    {
        $this->markParticipating();
        $volume = $this->volumeResolver->resolve();
        $fooHospital = $this->selection->demoHospital($volume, $this->selection->active($volume, $this->hospitals->participatingHospitals()));
        $this->assignOwner($fooHospital, $this->ensureParticipantRole($foo));

        $participating = array_values(array_filter(
            $this->hospitals->participatingHospitals(),
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
            $owner = UserFactory::new()->withoutAutorefresh()->create(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
            if (!$owner instanceof User) {
                throw new \LogicException('Expected User from UserFactory.');
            }
            $this->assignOwner($hospital, $owner);
        }

        $associate = UserFactory::new()->withoutAutorefresh()->create([
            'username' => 'associate',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        if (!$associate instanceof User) {
            throw new \LogicException('Expected User from UserFactory.');
        }

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

    /**
     * Participation belongs to an installation, not to the catalog (ADR 020): every third hospital by name takes part.
     */
    private function markParticipating(): void
    {
        $since = new \DateTimeImmutable('2025-01-01 00:00:00');
        foreach ($this->hospitals->allHospitals() as $index => $hospital) {
            if (0 === $index % 3) {
                $hospital->setIsParticipating(true)->setParticipatingSince($since);
            }
        }

        $this->entityManager->flush();
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
