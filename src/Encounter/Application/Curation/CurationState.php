<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation;

use App\Encounter\Domain\Enum\MtsCatalogStatus;

/**
 * The MTS catalog and the plausibility problem areas of this instance as plain values, keyed by code: the source of
 * an export and the target an import is compared with (#1198).
 */
final readonly class CurationState
{
    /**
     * @param array<string, array<string, array{german_name: string, english_name: ?string, source: string, status: MtsCatalogStatus}>> $entries     by kind, then code
     * @param array<string, array<string, array{label: string, target_code: string}>>                                                   $aliases     by kind, then alias code
     * @param array<string, list<string>>                                                                                               $assignments indicator codes by diagram code
     * @param array<string, array{german_name: string, english_name: ?string, pzc: list<int>, rules: array<string, int>}>               $areas       by area code; rules: points by diagram code
     */
    public function __construct(
        public array $entries,
        public array $aliases,
        public array $assignments,
        public array $areas,
    ) {
    }

    /**
     * @return array{german_name: string, english_name: ?string, source: string, status: MtsCatalogStatus}|null
     */
    public function entry(string $kind, string $code): ?array
    {
        return $this->entries[$kind][$code] ?? null;
    }

    /**
     * @return array{label: string, target_code: string}|null
     */
    public function alias(string $kind, string $code): ?array
    {
        return $this->aliases[$kind][$code] ?? null;
    }

    public function isCurated(string $kind, string $code): bool
    {
        return MtsCatalogStatus::Curated === ($this->entries[$kind][$code]['status'] ?? null);
    }

    public function hasAssignment(string $diagram, string $indicator): bool
    {
        return \in_array($indicator, $this->assignments[$diagram] ?? [], true);
    }

    /**
     * The code of the area a PZC belongs to, or null.
     */
    public function areaOfPzc(int $pzc): ?string
    {
        foreach ($this->areas as $code => $area) {
            if (\in_array($pzc, $area['pzc'], true)) {
                return (string) $code;
            }
        }

        return null;
    }
}
