<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Allocations;

use App\Allocation\Application\Allocations\AllocationListHospitalScopeResolver;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class AllocationListHospitalScopeResolverTest extends DatabaseKernelTestCase
{
    private AllocationListHospitalScopeResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resolver = self::getContainer()->get(AllocationListHospitalScopeResolver::class);
    }

    public function testNoScopeReturnsNull(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $user,
            'owner' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);

        self::assertNull($this->resolver->resolveHospitalIds($user, null, null));
        self::assertNull($this->resolver->resolveHospitalIds($user, '', 5));
        self::assertNull($this->resolver->resolveHospitalIds($user, null, 5));
    }

    public function testMyHospitalsWithoutDetailReturnsAccessibleIds(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $user,
            'owner' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);

        self::assertSame(
            [(int) $hospital->getId()],
            $this->resolver->resolveHospitalIds($user, 'my_hospitals', null),
        );
    }

    public function testMyHospitalsWithAccessibleDetailReturnsSingleId(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $user,
            'owner' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);

        self::assertSame(
            [(int) $hospital->getId()],
            $this->resolver->resolveHospitalIds($user, 'my_hospitals', (int) $hospital->getId()),
        );
    }

    public function testMyHospitalsWithInaccessibleDetailReturnsEmptyList(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $other]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $other, 'state' => $state]);
        HospitalFactory::createOne([
            'createdBy' => $other,
            'owner' => $other,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);

        self::assertSame([], $this->resolver->resolveHospitalIds($user, 'my_hospitals', 99));
    }

    public function testGranteeCanResolveGrantedHospital(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $owner]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $owner, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $owner,
            'owner' => $owner,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        self::assertTrue($this->resolver->canUseFilter($grantee));
        self::assertSame(
            [(int) $hospital->getId()],
            $this->resolver->resolveHospitalIds($grantee, 'my_hospitals', null),
        );
    }

    public function testMyHospitalsWithoutUserReturnsEmptyList(): void
    {
        self::assertSame([], $this->resolver->resolveHospitalIds(null, 'my_hospitals', null));
    }

    public function testHospitalFilterResolvesAggregateScope(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $user,
            'owner' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);

        self::assertSame(
            [(int) $hospital->getId()],
            $this->resolver->resolveHospitalIdsFromQuery($user, 'my_hospitals', null, null),
        );
    }

    public function testHospitalFilterResolvesSingleHospital(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $state = StateFactory::createOne(['createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['createdBy' => $user, 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'createdBy' => $user,
            'owner' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
        ]);

        self::assertSame(
            [(int) $hospital->getId()],
            $this->resolver->resolveHospitalIdsFromQuery($user, (string) $hospital->getId(), null, null),
        );
    }
}
