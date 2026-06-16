<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Repository;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UserRepositoryGrantDatalistTest extends KernelTestCase
{
    use Factories;

    private UserRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testDatalistExcludesOwnerAndExistingGrantUsers(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'owner-user']);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grantee-user']);
        $available = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'available-user']);
        UserFactory::createOne(['roles' => ['ROLE_USER'], 'username' => 'plain-user']);

        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        $datalist = $this->repository->findGrantEligibleUserDatalist(
            $hospital->_real(),
            [(int) $grantee->getId()],
        );

        $labels = array_column($datalist, 'label');
        $ids = array_column($datalist, 'id');

        self::assertContains('available-user', $labels);
        self::assertNotContains('owner-user', $labels);
        self::assertNotContains('grantee-user', $labels);
        self::assertNotContains('plain-user', $labels);
        self::assertContains((int) $available->getId(), $ids);
    }
}
