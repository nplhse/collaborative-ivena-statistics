<?php

declare(strict_types=1);

namespace App\Import\Domain\Service;

use App\Import\Application\DTO\ImportSummary;
use App\Import\Domain\Entity\Import;

final class ImportEvaluation
{
    public const float COMPLETE_MAX_RATIO = 0.05;

    public const float FAILED_MIN_RATIO = 0.35;

    public static function apply(Import $import, ImportSummary $summary, int $runtimeMs): void
    {
        if (0 === $summary->total) {
            $import->markAsFailed($runtimeMs);

            return;
        }

        $ratio = $summary->rejectionRatio();

        if ($ratio < self::COMPLETE_MAX_RATIO) {
            $import->markAsCompleted(
                total: $summary->total,
                ok: $summary->ok,
                rejected: $summary->rejected,
                runtimeMs: $runtimeMs,
            );

            return;
        }

        if ($ratio >= self::FAILED_MIN_RATIO) {
            $import->markAsFailed($runtimeMs);

            return;
        }

        $import->markAsPartial(
            total: $summary->total,
            ok: $summary->ok,
            rejected: $summary->rejected,
            runtimeMs: $runtimeMs,
        );
    }
}
