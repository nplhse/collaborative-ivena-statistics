<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Import\Application\Exception\ImportException;

/**
 * IVENA care-level labels. Unknown labels reject the row so a new value is a catalog change.
 */
final class ClosureCareLevelCatalog
{
    public function resolve(string $label): ClosureCareLevel
    {
        $normalized = $this->normalize($label);

        return match ($normalized) {
            'notfallversorgung' => ClosureCareLevel::EMERGENCY,
            'stationare versorgung' => ClosureCareLevel::INPATIENT,
            'ambulante versorgung' => ClosureCareLevel::OUTPATIENT,
            'sonstige' => ClosureCareLevel::OTHER,
            default => throw new ImportException(message: 'Unknown closure care level', field: 'careLevel', value: $label, codeStr: 'UNKNOWN_CARE_LEVEL'),
        };
    }

    private function normalize(string $label): string
    {
        $label = mb_strtolower(trim($label), 'UTF-8');
        $label = strtr($label, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
        $collapsed = preg_replace('/\s+/', ' ', $label);

        return $collapsed ?? $label;
    }
}
