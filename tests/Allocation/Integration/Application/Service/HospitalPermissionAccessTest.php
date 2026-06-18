<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Service;

use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalPermissionAccessTest extends KernelTestCase
{
    use Factories;

    private HospitalPermissionAccess $access;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->access = self::getContainer()->get(HospitalPermissionAccess::class);
    }

    public function testOwnerHasAllPermissions(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = $this->createHospitalForOwner($owner);

        self::assertTrue($this->access->hasPermission($owner, (int) $hospital->getId(), HospitalPermission::Import));
        self::assertTrue($this->access->hasPermission($owner, (int) $hospital->getId(), HospitalPermission::Benchmarking));
        self::assertTrue($this->access->canManageAccessGrants($owner, $hospital));
    }

    public function testGrantUserHasOnlyAssignedPermissions(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = $this->createHospitalForOwner($owner);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Statistics,
            ]),
            'createdBy' => $owner,
        ]);

        $hospitalId = (int) $hospital->getId();

        self::assertTrue($this->access->hasPermission($grantee, $hospitalId, HospitalPermission::Statistics));
        self::assertFalse($this->access->hasPermission($grantee, $hospitalId, HospitalPermission::Benchmarking));
        self::assertFalse($this->access->hasPermission($grantee, $hospitalId, HospitalPermission::Import));
        self::assertFalse($this->access->canManageAccessGrants($grantee, $hospital));
    }

    public function testResolveHospitalIdsWithImportPermission(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = $this->createHospitalForOwner($owner);
        $foreign = $this->createHospitalForOwner(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
            'createdBy' => $owner,
        ]);

        $ids = $this->access->resolveHospitalIdsWithPermission($grantee, HospitalPermission::Import);

        self::assertSame([(int) $hospital->getId()], $ids);
        self::assertNotContains((int) $foreign->getId(), $ids);
    }

    public function testAdminCanEditAndManageAccessGrantsForForeignHospital(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $hospital = $this->createHospitalForOwner($owner);

        self::assertTrue($this->access->canEditHospital($admin, $hospital));
        self::assertTrue($this->access->canManageAccessGrants($admin, $hospital));
    }

    private function createHospitalForOwner(object $owner): object
    {
        StateFactory::createOne();
        DispatchAreaFactory::createOne();

        return HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => UserFactory::createOne(),
        ]);
    }
}
