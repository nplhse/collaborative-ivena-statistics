<?php

namespace App\Import\Infrastructure\Adapter;

use App\Import\Application\Contracts\RowTypeDetectorInterface;
use App\Import\Domain\Enum\AllocationRowType;

final class RuleBasedRowTypeDetector implements RowTypeDetectorInterface
{
    /**
     * @param array<string,string> $row
     */
    #[\Override]
    public function detect(array $row): ?AllocationRowType
    {
        $allocationSignals = [
            'datum_erstellungsdatum',
            'uhrzeit_erstellungsdatum',
            'pzc',
            'pzc_und_text',
            'fachgebiet',
            'fachbereich',
            'grund',
            'dringlichkeit',
        ];

        foreach ($allocationSignals as $field) {
            $value = $row[$field] ?? null;
            if (null !== $value && '' !== \trim($value)) {
                return AllocationRowType::ALLOCATION;
            }
        }

        return null;
    }
}
