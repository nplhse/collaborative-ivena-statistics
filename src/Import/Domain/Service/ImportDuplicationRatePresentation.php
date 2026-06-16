<?php

declare(strict_types=1);

namespace App\Import\Domain\Service;

use App\Import\Domain\DTO\ImportRateBadge;

final class ImportDuplicationRatePresentation
{
    public const float LOW_MAX_RATIO = 0.10;

    public const float ELEVATED_MAX_RATIO = 0.35;

    public const float HIGH_MIN_RATIO = 0.50;

    public static function forPercent(float $percent): ImportRateBadge
    {
        $ratio = $percent / 100.0;

        if ($ratio < self::LOW_MAX_RATIO) {
            return new ImportRateBadge('azure', 'tabler:copy');
        }

        if ($ratio < self::ELEVATED_MAX_RATIO) {
            return new ImportRateBadge('yellow', 'tabler:circle-half');
        }

        if ($ratio < self::HIGH_MIN_RATIO) {
            return new ImportRateBadge('orange', 'tabler:alert-triangle');
        }

        return new ImportRateBadge('red', 'tabler:alert-triangle');
    }
}
