<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

use App\Allocation\Domain\Enum\ClosureReason;
use App\Import\Application\Exception\ImportException;

/**
 * Shared closure reasons. "k.A." is a category, not an empty field.
 */
final class ClosureReasonCatalog
{
    public function resolve(string $label): ClosureReason
    {
        $normalized = mb_strtolower(trim($label), 'UTF-8');

        return match ($normalized) {
            'überlastung der notaufnahme' => ClosureReason::EMERGENCY_DEPARTMENT_OVERLOAD,
            'keine bettenkapazitäten' => ClosureReason::NO_BED_CAPACITY,
            'technische störung' => ClosureReason::TECHNICAL_FAULT,
            'op-meldung' => ClosureReason::OPERATING_ROOM_NOTICE,
            'k.a.' => ClosureReason::NOT_SPECIFIED,
            default => throw new ImportException(message: 'Unknown closure reason', field: 'reason', value: $label, codeStr: 'UNKNOWN_REASON'),
        };
    }
}
