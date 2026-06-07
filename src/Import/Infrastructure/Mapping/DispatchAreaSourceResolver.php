<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Mapping;

final class DispatchAreaSourceResolver
{
    private const string COORDINATION_MARKER = 'Koordinierungsstelle';

    /**
     * @param array<string, string|null> $row
     */
    public function resolve(array $row): DispatchAreaImportSource
    {
        $zuweisungDurch = $this->getStringOrNull($row, 'zuweisung_durch');

        if (null !== $zuweisungDurch && str_contains($zuweisungDurch, self::COORDINATION_MARKER)) {
            return new DispatchAreaImportSource(
                $this->getStringOrNull($row, 'versorgungsbereich'),
                'versorgungsbereich',
            );
        }

        if (null !== $zuweisungDurch && '' !== $zuweisungDurch) {
            return new DispatchAreaImportSource($zuweisungDurch, 'zuweisung_durch');
        }

        return new DispatchAreaImportSource(
            $this->getStringOrNull($row, 'versorgungsbereich'),
            'versorgungsbereich',
        );
    }

    /**
     * @param array<string, string|null> $row
     */
    public function resolveRowKey(array $row): string
    {
        return $this->resolve($row)->column;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function getStringOrNull(array $row, string $key): ?string
    {
        if (!\array_key_exists($key, $row)) {
            return null;
        }

        $value = \trim((string) $row[$key]);

        return '' === $value ? null : $value;
    }
}
