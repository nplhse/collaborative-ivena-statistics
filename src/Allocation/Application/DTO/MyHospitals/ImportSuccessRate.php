<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO\MyHospitals;

/**
 * Successful imports (completed or partial) as a share of finished imports, rounded to whole percent.
 */
final class ImportSuccessRate
{
    public static function percent(int $successful, int $finished): ?int
    {
        if ($finished <= 0) {
            return null;
        }

        return (int) round(100.0 * (float) $successful / (float) $finished);
    }
}
