<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Export;

use App\Allocation\Application\Export\ExportAccessService;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExportAccessServiceTest extends KernelTestCase
{
    use Factories;

    private ExportAccessService $service;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(ExportAccessService::class);
    }

    public function testResolveEffectiveHospitalIdsReturnsAllWhenNoneSelected(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospitalA = HospitalFactory::createOne(['owner' => $owner]);
        $hospitalB = HospitalFactory::createOne(['owner' => $owner]);

        $allowed = $this->service->resolveExportHospitalIds($owner);
        self::assertEqualsCanonicalizing(
            [(int) $hospitalA->getId(), (int) $hospitalB->getId()],
            $this->service->resolveEffectiveHospitalIds($owner, null),
        );
        self::assertEqualsCanonicalizing($allowed, $this->service->resolveEffectiveHospitalIds($owner, []));
    }

    public function testResolveEffectiveHospitalIdsIntersectsWithAllowed(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $other = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospitalA = HospitalFactory::createOne(['owner' => $owner]);
        $hospitalB = HospitalFactory::createOne(['owner' => $owner]);
        $foreign = HospitalFactory::createOne(['owner' => $other]);

        self::assertSame(
            [(int) $hospitalA->getId()],
            $this->service->resolveEffectiveHospitalIds($owner, [(int) $hospitalA->getId(), (int) $foreign->getId()]),
        );

        self::assertSame(
            [(int) $hospitalB->getId()],
            $this->service->resolveEffectiveHospitalIds($owner, [(int) $hospitalB->getId()]),
        );
    }

    public function testGrantUserCanOnlySelectGrantedHospital(): void
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        HospitalFactory::createOne(['owner' => $owner]);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Export,
            ]),
        ]);

        self::assertSame(
            [(int) $hospital->getId()],
            $this->service->resolveEffectiveHospitalIds($grantee, [(int) $hospital->getId()]),
        );
    }
}
