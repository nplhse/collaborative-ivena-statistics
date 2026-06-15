<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Domain;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use PHPUnit\Framework\TestCase;

final class HospitalPermissionMaskTest extends TestCase
{
    public function testBenchmarkingImpliesStatisticsAndView(): void
    {
        $mask = HospitalPermissionMask::fromPermissions([HospitalPermission::Benchmarking]);

        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::Benchmarking));
        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::Statistics));
        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::View));
        self::assertFalse(HospitalPermissionMask::has($mask, HospitalPermission::Import));
    }

    public function testStatisticsDoesNotImplyBenchmarking(): void
    {
        $mask = HospitalPermissionMask::fromPermissions([HospitalPermission::Statistics]);

        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::Statistics));
        self::assertFalse(HospitalPermissionMask::has($mask, HospitalPermission::Benchmarking));
    }

    public function testBenchmarkingWithoutStatisticsIsInvalid(): void
    {
        $raw = HospitalPermission::Benchmarking->value;

        self::assertFalse(HospitalPermissionMask::isValid($raw));
    }
}
