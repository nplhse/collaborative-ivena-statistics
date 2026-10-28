<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\User\Application\AdministrativeUser\AdministrativeUserService;
use App\User\Application\AdministrativeUser\CreateUserInput;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The master-data part of the demo wizard (#791), kept apart from data generation: the participant account, a new
 * synthetic hospital, and for an existing hospital only what generation needs and is missing (participation, an
 * owner, access). Name, tier, size, beds, address, coordinates and dispatch area of an existing hospital are never
 * written. Nothing here flushes on its own except the account; the wizard decides when to commit.
 */
final readonly class DemoHospitalSetup
{
    /** Domain of the participant accounts the wizard creates; `.invalid` can never receive mail (RFC 2606). */
    public const string EMAIL_DOMAIN = 'demo.invalid';

    public const string USERNAME_PREFIX = 'demo-';

    public function __construct(
        private AdministrativeUserService $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * An active, verified participant account for the presented hospital, with access to closure analytics.
     */
    public function createParticipant(string $hospitalName): DemoParticipant
    {
        $slug = substr(new AsciiSlugger()->slug($hospitalName)->lower()->toString(), 0, 40);
        $username = rtrim(self::USERNAME_PREFIX.('' === $slug ? 'hospital' : $slug), '-').'-'.bin2hex(random_bytes(2));
        $email = $username.'@'.self::EMAIL_DOMAIN;
        // Credentials are not generated data: they must differ on every run and are shown once.
        $password = 'Demo-'.bin2hex(random_bytes(8));

        $user = $this->users->create(new CreateUserInput($username, $email, $password, grantAdmin: false));
        $user->setRoles(array_values(array_unique([...$user->getRoles(), UserRole::PARTICIPANT, UserRole::CLOSURE_BETA])));

        return new DemoParticipant((int) $user->getId(), $username, $email, $password);
    }

    /**
     * A new participating hospital owned by the participant. Participation starts with the first generated month.
     */
    public function createHospital(NewDemoHospital $new, User $owner, \DateTimeImmutable $participatingSince): Hospital
    {
        $dispatchArea = $this->entityManager->find(DispatchArea::class, $new->dispatchAreaId)
            ?? throw new \DomainException(\sprintf('Unknown dispatch area #%d.', $new->dispatchAreaId));
        $state = $dispatchArea->getState();
        if (!$state instanceof State) {
            throw new \DomainException(\sprintf('The dispatch area %s has no federal state.', (string) $dispatchArea->getName()));
        }
        if ($this->nameTaken($new->name)) {
            throw new \DomainException(\sprintf('A hospital named "%s" already exists. Choose another name or use the existing hospital.', $new->name));
        }

        $hospital = new Hospital()
            ->setName(trim($new->name))
            ->setDispatchArea($dispatchArea)
            ->setState($state)
            ->setLocation($new->location)
            ->setTier($new->tier)
            ->setSize($new->size)
            ->setBeds($new->beds)
            ->setLatitude($new->latitude)
            ->setLongitude($new->longitude)
            ->setOwner($owner)
            ->setParticipatingSince($participatingSince)
            ->setIsParticipating(true);
        $hospital->setCreatedBy($owner);
        $this->entityManager->persist($hospital);

        return $hospital;
    }

    /**
     * Gives an existing hospital what generation needs, without touching its master data.
     *
     * @return list<string> what was changed, for the summary
     */
    public function prepareExisting(Hospital $hospital, User $participant, \DateTimeImmutable $participatingSince): array
    {
        $changes = [];
        if (!$hospital->isParticipating()) {
            $hospital->setParticipatingSince($participatingSince)->setIsParticipating(true);
            $changes[] = 'participation switched on';
        }

        $owner = $hospital->getOwner();
        if (!$owner instanceof User) {
            $hospital->setOwner($participant);
            $changes[] = 'the demo participant owns the hospital';
        } else {
            $grant = new HospitalAccessGrant()
                ->setHospital($hospital)
                ->setUser($participant)
                ->setPermissions(self::fullAccess())
                ->setCreatedBy($participant);
            $hospital->addAccessGrant($grant);
            $this->entityManager->persist($grant);
            $changes[] = \sprintf('access grant with all permissions for the demo participant (owner %s stays; both are notified on completion)', $owner->getUserIdentifier());
        }

        return $changes;
    }

    public function nameTaken(string $name): bool
    {
        return null !== $this->entityManager->getRepository(Hospital::class)->findOneBy(['name' => trim($name)]);
    }

    public static function fullAccess(): int
    {
        return HospitalPermissionMask::fromPermissions(HospitalPermission::cases());
    }
}
