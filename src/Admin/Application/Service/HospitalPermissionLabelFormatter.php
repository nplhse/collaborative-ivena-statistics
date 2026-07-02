<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;

final class HospitalPermissionLabelFormatter
{
    public static function formatMask(int $mask): string
    {
        if (0 === $mask) {
            return '—';
        }

        $labels = [];
        foreach (HospitalPermission::assignableCases() as $permission) {
            if (HospitalPermissionMask::has($mask, $permission)) {
                $labels[] = $permission->name;
            }
        }

        return [] === $labels ? '—' : implode(', ', $labels);
    }
}
