<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Infrastructure\Security;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Security\Voter\HospitalVoter;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalVoterTest extends KernelTestCase
{
    use Factories;

    private HospitalVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = self::getContainer()->get(HospitalVoter::class);
    }

    public function testAccessGrantedForHospitalOwner(): void
    {
        [$owner, $hospital] = $this->createOwnedHospital();
        $token = $this->createToken($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($token, $hospital, [HospitalVoter::ACCESS]),
        );
    }

    public function testAccessGrantedForAdmin(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $hospital = $this->createHospitalForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin), $hospital, [HospitalVoter::ACCESS]),
        );
    }

    public function testAccessDeniedForForeignParticipant(): void
    {
        [, $hospital] = $this->createOwnedHospital();
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder), $hospital, [HospitalVoter::ACCESS]),
        );
    }

    public function testEditGrantedForOwner(): void
    {
        [$owner, $hospital] = $this->createOwnedHospital();

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $hospital, [HospitalVoter::EDIT]),
        );
    }

    public function testEditGrantedForAdmin(): void
    {
        [, $hospital] = $this->createOwnedHospital();
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin), $hospital, [HospitalVoter::EDIT]),
        );
    }

    public function testDeniedForAnonymousUser(): void
    {
        [, $hospital] = $this->createOwnedHospital();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($token, $hospital, [HospitalVoter::EDIT]),
        );
    }

    public function testAccessDeniedWhenHospitalHasNoId(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = $this->createMock(Hospital::class);
        $hospital->method('getId')->willReturn(null);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($owner), $hospital, [HospitalVoter::ACCESS]),
        );
    }

    public function testManageAccessGrantsGrantedForOwner(): void
    {
        [$owner, $hospital] = $this->createOwnedHospital();

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner), $hospital, [HospitalVoter::MANAGE_ACCESS_GRANTS]),
        );
    }

    public function testManageAccessGrantsDeniedForNonOwner(): void
    {
        [, $hospital] = $this->createOwnedHospital();
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder), $hospital, [HospitalVoter::MANAGE_ACCESS_GRANTS]),
        );
    }

    public function testManageAccessGrantsGrantedForAdmin(): void
    {
        [, $hospital] = $this->createOwnedHospital();
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin), $hospital, [HospitalVoter::MANAGE_ACCESS_GRANTS]),
        );
    }

    public function testAccessGrantedForUserWithViewGrant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = $this->createHospitalForOwner($owner);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($grantee), $hospital, [HospitalVoter::ACCESS]),
        );
    }

    public function testSupportsTypeForHospitalOnly(): void
    {
        self::assertTrue($this->voter->supportsType(Hospital::class));
        self::assertFalse($this->voter->supportsType(Import::class));
    }

    /**
     * @return array{0: User, 1: object}
     */
    private function createOwnedHospital(): array
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = $this->createHospitalForOwner($owner);

        return [$owner, $hospital];
    }

    private function createHospitalForOwner(object $owner): object
    {
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        return HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
