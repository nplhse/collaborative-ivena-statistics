<?php

declare(strict_types=1);

namespace App\Import\Domain\Service;

use App\Import\Domain\DTO\ImportRateBadge;

final class ImportRejectionRatePresentation
{
    public static function forPercent(float $percent): ImportRateBadge
    {
        $ratio = $percent / 100.0;

        if ($ratio < ImportEvaluation::COMPLETE_MAX_RATIO) {
            return new ImportRateBadge('green', 'tabler:circle-check');
        }

        if ($ratio < ImportEvaluation::FAILED_MIN_RATIO) {
            return new ImportRateBadge('yellow', 'tabler:circle-half');
        }

        return new ImportRateBadge('red', 'tabler:alert-triangle');
    }
}
