<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

use App\Allocation\Domain\Enum\ClosureFacilityKind;
use App\Import\Application\Exception\ImportException;

final class ClosureFacilityKindCatalog
{
    public function resolve(string $label): ClosureFacilityKind
    {
        $normalized = mb_strtolower(trim($label), 'UTF-8');

        return match ($normalized) {
            'klinik' => ClosureFacilityKind::CLINIC,
            default => throw new ImportException(message: 'Unknown closure facility kind', field: 'facilityKind', value: $label, codeStr: 'UNKNOWN_FACILITY_KIND'),
        };
    }
}
