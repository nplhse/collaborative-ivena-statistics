<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Entity;

use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use PHPUnit\Framework\TestCase;

final class HospitalAccessGrantTest extends TestCase
{
    public function testSetPermissionsStoresNormalizedMaskFromPermissionList(): void
    {
        $grant = new HospitalAccessGrant();
        $mask = HospitalPermissionMask::fromPermissions([
            HospitalPermission::View,
            HospitalPermission::Statistics,
        ]);

        $grant->setPermissions($mask);

        self::assertSame($mask, $grant->getPermissions());
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::View));
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::Statistics));
    }

    public function testSetPermissionsNormalizesIncompleteRawMaskBeforeStore(): void
    {
        $grant = new HospitalAccessGrant();

        // Raw Statistics bit alone is invalid for has()/isValid(), but setPermissions normalizes first.
        $grant->setPermissions(HospitalPermission::Statistics->value);

        self::assertSame(3, $grant->getPermissions());
        self::assertTrue(HospitalPermissionMask::isValid($grant->getPermissions()));
    }

    public function testSetPermissionsStripsUnknownBitsViaNormalize(): void
    {
        $grant = new HospitalAccessGrant();

        $grant->setPermissions(32);

        self::assertSame(0, $grant->getPermissions());
    }

    public function testSetPermissionsNormalizesBenchmarkingToIncludeDependencies(): void
    {
        $grant = new HospitalAccessGrant();

        $grant->setPermissions(HospitalPermission::Benchmarking->value);

        self::assertSame(19, $grant->getPermissions());
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::Benchmarking));
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::Statistics));
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::View));
    }
}
