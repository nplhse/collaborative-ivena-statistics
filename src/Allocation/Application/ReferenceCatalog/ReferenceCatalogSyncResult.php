<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

final readonly class ReferenceCatalogSyncResult
{
    /**
     * @param array<string, int> $createdByType
     * @param array<string, int> $skippedByType
     * @param array<string, int> $updatedByType
     * @param list<string>       $warnings
     */
    public function __construct(
        public array $createdByType = [],
        public array $skippedByType = [],
        public array $updatedByType = [],
        public array $warnings = [],
    ) {
    }

    public function created(): int
    {
        return array_sum($this->createdByType);
    }

    public function skipped(): int
    {
        return array_sum($this->skippedByType);
    }

    public function updated(): int
    {
        return array_sum($this->updatedByType);
    }

    public function hasChanges(): bool
    {
        return $this->created() > 0 || $this->updated() > 0;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public function tableRows(): array
    {
        $types = array_unique([
            ...array_keys($this->createdByType),
            ...array_keys($this->skippedByType),
            ...array_keys($this->updatedByType),
        ]);
        sort($types);

        $rows = [];
        foreach ($types as $type) {
            $rows[] = [
                $type,
                (string) ($this->createdByType[$type] ?? 0),
                (string) ($this->skippedByType[$type] ?? 0),
                (string) ($this->updatedByType[$type] ?? 0),
            ];
        }

        return $rows;
    }
}
