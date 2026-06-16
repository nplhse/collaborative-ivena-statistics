<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Infrastructure\Adapter;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;

final class DoctrineHospitalAccessTest extends DatabaseKernelTestCase
{
    private HospitalAccessInterface $access;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->access = self::getContainer()->get(HospitalAccessInterface::class);
    }

    public function testUserWithoutParticipantCannotUseMyHospitalsScope(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);

        self::assertFalse($this->access->canUseMyHospitalsScope($user));
        self::assertFalse($this->access->isAdminHospitalScopeUser($user));
    }

    public function testParticipantWithoutOwnedHospitalsCannotUseScope(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        self::assertFalse($this->access->canUseMyHospitalsScope($user));
    }

    public function testParticipantWithOwnedHospitalsCanUseScope(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $otherOwner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $owned = HospitalFactory::createOne(['owner' => $user]);
        HospitalFactory::createOne(['owner' => $otherOwner]);

        self::assertTrue($this->access->canUseMyHospitalsScope($user));
        self::assertNotNull($owned->getId());
        self::assertTrue($this->access->canSelectHospitalScope($user, $owned->getId()));
        self::assertSame([$owned->getId()], $this->access->accessibleHospitalIds($user));
    }

    public function testAdminCanAlwaysUseScopeAndSeesAllHospitals(): void
    {
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $foreignOwner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $owned = HospitalFactory::createOne(['owner' => $owner]);
        $other = HospitalFactory::createOne(['owner' => $foreignOwner]);

        self::assertTrue($this->access->isAdminHospitalScopeUser($admin));
        self::assertTrue($this->access->canUseMyHospitalsScope($admin));
        self::assertContains($owned->getId(), $this->access->accessibleHospitalIds($admin));
        self::assertContains($other->getId(), $this->access->accessibleHospitalIds($admin));
    }

    public function testAdminAndParticipantUsesAdminVariant(): void
    {
        $dual = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT']]);
        $otherOwner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $dual]);
        $foreign = HospitalFactory::createOne(['owner' => $otherOwner]);

        self::assertTrue($this->access->isAdminHospitalScopeUser($dual));
        self::assertTrue($this->access->canUseMyHospitalsScope($dual));
        self::assertContains($foreign->getId(), $this->access->accessibleHospitalIds($dual));
    }
}
