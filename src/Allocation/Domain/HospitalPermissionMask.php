<?php

declare(strict_types=1);

namespace App\Allocation\Domain;

use App\Allocation\Domain\Enum\HospitalPermission;

final class HospitalPermissionMask
{
    private const int ALL = 31;

    public static function has(int $mask, HospitalPermission $permission): bool
    {
        $required = self::requiredBits($permission);

        return ($mask & $required) === $required;
    }

    public static function normalize(int $mask): int
    {
        $normalized = 0;

        foreach (HospitalPermission::assignableCases() as $permission) {
            if (($mask & $permission->value) !== 0) {
                $normalized |= self::requiredBits($permission);
            }
        }

        return $normalized;
    }

    /**
     * @param list<HospitalPermission> $permissions
     */
    public static function fromPermissions(array $permissions): int
    {
        $mask = 0;
        foreach ($permissions as $permission) {
            $mask |= $permission->value;
        }

        return self::normalize($mask);
    }

    public static function requiredBits(HospitalPermission $permission): int
    {
        return match ($permission) {
            HospitalPermission::View => HospitalPermission::View->value,
            HospitalPermission::Statistics => HospitalPermission::View->value | HospitalPermission::Statistics->value,
            HospitalPermission::Import => HospitalPermission::View->value | HospitalPermission::Import->value,
            HospitalPermission::Export => HospitalPermission::View->value | HospitalPermission::Export->value,
            HospitalPermission::Benchmarking => HospitalPermission::View->value
                | HospitalPermission::Statistics->value
                | HospitalPermission::Benchmarking->value,
        };
    }

    public static function isValid(int $mask): bool
    {
        if (($mask & ~self::ALL) !== 0) {
            return false;
        }

        if (self::has($mask, HospitalPermission::Benchmarking) && !self::has($mask, HospitalPermission::Statistics)) {
            return false;
        }

        return self::normalize($mask) === $mask;
    }
}
