<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

use App\Import\Application\Exception\ImportException;

final class ClosureHospitalGuard
{
    public function assertMatches(?string $hospitalName, ?string $shortName): void
    {
        $expected = $this->normalize($hospitalName);
        $actual = $this->normalize($shortName);

        if ('' === $expected || '' === $actual || $expected !== $actual) {
            throw new ImportException(message: 'Closure row hospital does not match the selected hospital', field: 'hospital', value: $shortName, codeStr: 'HOSPITAL_MISMATCH');
        }
    }

    private function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $collapsed = preg_replace('/\s+/', ' ', $value);

        return $collapsed ?? $value;
    }
}
