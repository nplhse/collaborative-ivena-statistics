<?php

declare(strict_types=1);

namespace App\Import\Domain\Service;

use App\Import\Application\DTO\ImportSummary;
use App\Import\Domain\Entity\Import;

final class ImportEvaluation
{
    public const float COMPLETE_MAX_RATIO = 0.05;

    public const float FAILED_MIN_RATIO = 0.35;

    /**
     * Whether a run with this summary ends as failed: an empty file or too many rejected rows.
     */
    public static function fails(ImportSummary $summary): bool
    {
        return 0 === $summary->total || $summary->rejectionRatio() >= self::FAILED_MIN_RATIO;
    }

    public static function apply(Import $import, ImportSummary $summary, int $runtimeMs): void
    {
        if (self::fails($summary)) {
            $import->markAsFailed(
                runtimeMs: $runtimeMs,
                total: $summary->total,
                ok: $summary->ok,
                rejected: $summary->rejected,
            );

            return;
        }

        if ($summary->rejectionRatio() < self::COMPLETE_MAX_RATIO) {
            $import->markAsCompleted(
                total: $summary->total,
                ok: $summary->ok,
                rejected: $summary->rejected,
                runtimeMs: $runtimeMs,
            );

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
