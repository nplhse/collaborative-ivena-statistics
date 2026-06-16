<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Security;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Security\Voter\ImportVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportVoterTest extends KernelTestCase
{
    use Factories;

    private ImportVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = self::getContainer()->get(ImportVoter::class);
    }

    public function testViewGrantedForHospitalOwner(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner->_real()), $import->_real(), [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedForForeignParticipant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder->_real()), $import->_real(), [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedForAnonymousUser(): void
    {
        $import = $this->createMock(Import::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($token, $import, [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedWhenImportHasNoHospital(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createMock(Import::class);
        $import->method('getHospital')->willReturn(null);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($owner->_real()), $import, [ImportVoter::VIEW]),
        );
    }

    public function testDeleteGrantedForHospitalOwner(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($owner->_real()), $import->_real(), [ImportVoter::DELETE]),
        );
    }

    public function testDeleteGrantedForAdmin(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($admin->_real()), $import->_real(), [ImportVoter::DELETE]),
        );
    }

    public function testDeleteGrantedForCreatorWithoutHospitalAccess(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $creator = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner, $creator);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($creator->_real()), $import->_real(), [ImportVoter::DELETE]),
        );
    }

    public function testDeleteDeniedForForeignParticipant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($intruder->_real()), $import->_real(), [ImportVoter::DELETE]),
        );
    }

    public function testViewGrantedForUserWithImportGrant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_GRANTED,
            $this->voter->vote($this->createToken($grantee->_real()), $import->_real(), [ImportVoter::VIEW]),
        );
    }

    public function testViewDeniedForUserWithOnlyViewGrant(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $import = $this->createImportForOwner($owner);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $import->getHospital(),
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        self::assertSame(
            Voter::ACCESS_DENIED,
            $this->voter->vote($this->createToken($grantee->_real()), $import->_real(), [ImportVoter::VIEW]),
        );
    }

    public function testSupportsTypeForImportOnly(): void
    {
        self::assertTrue($this->voter->supportsType(Import::class));
        self::assertFalse($this->voter->supportsType(Hospital::class));
    }

    private function createImportForOwner(object $owner, ?object $createdBy = null): object
    {
        $createdBy ??= UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $createdBy,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/test.csv',
        ]);
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
