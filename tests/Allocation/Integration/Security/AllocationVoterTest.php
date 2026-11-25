<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Security;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Security\Voter\AllocationVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Explore shows an allocation only to participants with access to its hospital, and to administrators (#1566, ADR 044):
 * hospital "Own" is owned by the participant, "Granted" grants them View only, "Foreign" belongs to someone else.
 */
#[ResetDatabase]
final class AllocationVoterTest extends KernelTestCase
{
    use Factories;

    private User $participant;

    private Hospital $own;

    private Hospital $granted;

    private Hospital $foreign;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->participant = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->own = HospitalFactory::createOne(['name' => 'Own', 'owner' => $this->participant]);
        $someoneElse = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->granted = HospitalFactory::createOne(['name' => 'Granted', 'owner' => $someoneElse]);
        $this->foreign = HospitalFactory::createOne(['name' => 'Foreign', 'owner' => $someoneElse]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $this->granted,
            'user' => $this->participant,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
        ]);
    }

    public function testParticipantSeesAllocationsOfOwnedAndGrantedHospitalsOnly(): void
    {
        self::assertSame(Voter::ACCESS_GRANTED, $this->vote($this->participant, $this->own));
        self::assertSame(Voter::ACCESS_GRANTED, $this->vote($this->participant, $this->granted));
        self::assertSame(Voter::ACCESS_DENIED, $this->vote($this->participant, $this->foreign));
    }

    public function testAdministratorSeesAllocationsOfEveryHospital(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        self::assertSame(Voter::ACCESS_GRANTED, $this->vote($admin, $this->foreign));
    }

    public function testOwnershipWithoutParticipantRoleIsNotEnough(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $hospital = HospitalFactory::createOne(['owner' => $user]);

        self::assertSame(Voter::ACCESS_DENIED, $this->vote($user, $hospital));
    }

    public function testAnAllocationWithoutHospitalIsForAdministratorsOnly(): void
    {
        $allocation = $this->createStub(Allocation::class);
        $allocation->method('getHospital')->willReturn(null);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        self::assertSame(Voter::ACCESS_DENIED, $this->voter()->vote(new UsernamePasswordToken($this->participant, 'main', $this->participant->getRoles()), $allocation, [AllocationVoter::VIEW]));
        self::assertSame(Voter::ACCESS_GRANTED, $this->voter()->vote(new UsernamePasswordToken($admin, 'main', $admin->getRoles()), $allocation, [AllocationVoter::VIEW]));
    }

    public function testAnonymousIsDenied(): void
    {
        self::assertSame(Voter::ACCESS_DENIED, $this->voter()->vote(new NullToken(), $this->allocationOf($this->own), [AllocationVoter::VIEW]));
    }

    private function vote(User $user, Hospital $hospital): int
    {
        return $this->voter()->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $this->allocationOf($hospital), [AllocationVoter::VIEW]);
    }

    private function allocationOf(Hospital $hospital): Allocation
    {
        $allocation = $this->createStub(Allocation::class);
        $allocation->method('getHospital')->willReturn($hospital);

        return $allocation;
    }

    private function voter(): AllocationVoter
    {
        $voter = self::getContainer()->get(AllocationVoter::class);
        self::assertInstanceOf(AllocationVoter::class, $voter);

        return $voter;
    }
}
