<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Domain;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::View));
        self::assertFalse(HospitalPermissionMask::has($mask, HospitalPermission::Benchmarking));
    }

    public function testBenchmarkingWithoutStatisticsIsInvalid(): void
    {
        $raw = HospitalPermission::Benchmarking->value;

        self::assertFalse(HospitalPermissionMask::isValid($raw));
    }

    public function testImportImpliesViewButNotBenchmarking(): void
    {
        $mask = HospitalPermissionMask::fromPermissions([HospitalPermission::Import]);

        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::Import));
        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::View));
        self::assertFalse(HospitalPermissionMask::has($mask, HospitalPermission::Statistics));
        self::assertFalse(HospitalPermissionMask::has($mask, HospitalPermission::Benchmarking));
    }

    public function testExportImpliesViewButNotImport(): void
    {
        $mask = HospitalPermissionMask::fromPermissions([HospitalPermission::Export]);

        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::Export));
        self::assertTrue(HospitalPermissionMask::has($mask, HospitalPermission::View));
        self::assertFalse(HospitalPermissionMask::has($mask, HospitalPermission::Import));
    }

    public function testEmptyPermissionsYieldZeroMask(): void
    {
        self::assertSame(0, HospitalPermissionMask::fromPermissions([]));
        self::assertTrue(HospitalPermissionMask::isValid(0));
    }

    public function testFullAssignableMaskIsValid(): void
    {
        $mask = HospitalPermissionMask::fromPermissions(HospitalPermission::assignableCases());

        self::assertSame(31, $mask);
        self::assertTrue(HospitalPermissionMask::isValid($mask));
    }

    #[DataProvider('provideRequiredBits')]
    public function testRequiredBits(HospitalPermission $permission, int $expected): void
    {
        self::assertSame($expected, HospitalPermissionMask::requiredBits($permission));
    }

    /**
     * @return iterable<string, array{HospitalPermission, int}>
     */
    public static function provideRequiredBits(): iterable
    {
        yield 'view' => [HospitalPermission::View, 1];
        yield 'statistics' => [HospitalPermission::Statistics, 3];
        yield 'import' => [HospitalPermission::Import, 5];
        yield 'export' => [HospitalPermission::Export, 9];
        yield 'benchmarking' => [HospitalPermission::Benchmarking, 19];
    }

    public function testHasRequiresAllImpliedBits(): void
    {
        self::assertFalse(HospitalPermissionMask::has(HospitalPermission::Statistics->value, HospitalPermission::Statistics));
        self::assertFalse(HospitalPermissionMask::has(HospitalPermission::Import->value, HospitalPermission::Import));
        self::assertTrue(HospitalPermissionMask::has(5, HospitalPermission::Import));
        self::assertFalse(HospitalPermissionMask::has(5, HospitalPermission::Statistics));
    }

    public function testIsValidRejectsUnknownBitsAndIncompleteMasks(): void
    {
        self::assertFalse(HospitalPermissionMask::isValid(32));
        self::assertFalse(HospitalPermissionMask::isValid(2));
        self::assertTrue(HospitalPermissionMask::isValid(3));
        self::assertTrue(HospitalPermissionMask::isValid(31));
    }

    public function testNormalizeFillsImpliedBitsAndStripsUnknownBits(): void
    {
        self::assertSame(3, HospitalPermissionMask::normalize(2));
        self::assertSame(19, HospitalPermissionMask::normalize(16));
        self::assertSame(0, HospitalPermissionMask::normalize(32));
        self::assertSame(5, HospitalPermissionMask::normalize(4));
    }
}
