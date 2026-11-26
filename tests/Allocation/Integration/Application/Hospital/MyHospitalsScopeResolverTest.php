<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Hospital;

use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsEntry;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsScopeResolver;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class MyHospitalsScopeResolverTest extends KernelTestCase
{
    use Factories;

    private const int ALL_PERMISSIONS = 31;

    private MyHospitalsScopeResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = self::getContainer()->get(MyHospitalsScopeResolver::class);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
    }

    public function testOwnerGetsAllPermissionsOnOwnedHospitals(): void
    {
        $owner = $this->participant();
        $this->hospital('Owned', $owner);
        $this->hospital('Foreign', $this->participant());

        $scope = $this->resolver->resolve($owner);

        self::assertSame(['Owned'], $this->names($scope->entries));
        self::assertTrue($scope->entries[0]->owned);
        self::assertSame(self::ALL_PERMISSIONS, $scope->entries[0]->permissionMask);
    }

    public function testGrantHolderGetsGrantPermissions(): void
    {
        $grantee = $this->participant();
        $statistics = $this->hospital('Statistics grant', $this->participant());
        $import = $this->hospital('Import grant', $this->participant());
        $this->grant($statistics, $grantee, [HospitalPermission::View, HospitalPermission::Statistics]);
        $this->grant($import, $grantee, [HospitalPermission::View, HospitalPermission::Import]);

        $scope = $this->resolver->resolve($grantee);

        self::assertSame(['Import grant', 'Statistics grant'], $this->names($scope->entries));
        self::assertFalse($scope->entries[0]->owned);
        self::assertSame(1 | 4, $scope->entries[0]->permissionMask);
        self::assertSame(1 | 2, $scope->entries[1]->permissionMask);
    }

    public function testGrantWithoutViewIsNotListed(): void
    {
        $grantee = $this->participant();
        $hospital = $this->hospital('No view', $this->participant());
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => 0,
            'createdBy' => UserFactory::createOne(),
        ]);

        self::assertTrue($this->resolver->resolve($grantee)->isEmpty());
    }

    public function testOwnedAndGrantedHospitalsAreCombinedSortedByName(): void
    {
        $user = $this->participant();
        $this->hospital('Zeta owned', $user);
        $this->grant($this->hospital('Alpha granted', $this->participant()), $user, [HospitalPermission::View]);

        $scope = $this->resolver->resolve($user);

        self::assertSame(['Alpha granted', 'Zeta owned'], $this->names($scope->entries));
        self::assertSame(2, $scope->count());
    }

    public function testAdminWithoutOwnHospitalsGetsNoHospitals(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $this->hospital('Someone else', $this->participant());

        self::assertTrue($this->resolver->resolve($admin)->isEmpty());
    }

    public function testAdminGetsOwnedAndGrantedHospitalsWithAllPermissions(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $this->hospital('Admin owned', $admin);
        $this->grant($this->hospital('Admin granted', $this->participant()), $admin, [HospitalPermission::View]);
        $this->hospital('Not related', $this->participant());

        $scope = $this->resolver->resolve($admin);

        self::assertSame(['Admin granted', 'Admin owned'], $this->names($scope->entries));
        self::assertSame(self::ALL_PERMISSIONS, $scope->entries[0]->permissionMask);
        self::assertFalse($scope->entries[0]->owned);
        self::assertTrue($scope->entries[1]->owned);
    }

    public function testUserWithoutHospitalsGetsEmptyScope(): void
    {
        $this->hospital('Someone else', $this->participant());

        $scope = $this->resolver->resolve($this->participant());

        self::assertTrue($scope->isEmpty());
        self::assertSame(0, $scope->count());
    }

    private function participant(): User
    {
        return UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
    }

    private function hospital(string $name, User $owner): Hospital
    {
        return HospitalFactory::createOne([
            'name' => $name,
            'owner' => $owner,
            'createdBy' => UserFactory::createOne(),
        ]);
    }

    /**
     * @param list<HospitalPermission> $permissions
     */
    private function grant(Hospital $hospital, User $user, array $permissions): void
    {
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $user,
            'permissions' => HospitalPermissionMask::fromPermissions($permissions),
            'createdBy' => UserFactory::createOne(),
        ]);
    }

    /**
     * @param list<MyHospitalsEntry> $entries
     *
     * @return list<string|null>
     */
    private function names(array $entries): array
    {
        return array_map(static fn (MyHospitalsEntry $entry): ?string => $entry->hospital->getName(), $entries);
    }
}
