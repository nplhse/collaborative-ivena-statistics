<?php

declare(strict_types=1);

namespace App\Allocation\Application\DTO;

final readonly class CatalogNormalizationSummary
{
    /**
     * @param list<CatalogMappedRawTerm>  $raws
     * @param array<string, int>          $statusCounts
     * @param list<CatalogQualityWarning> $warnings
     */
    public function __construct(
        public array $raws,
        public array $statusCounts,
        public array $warnings,
    ) {
    }

    public function synonymCount(): int
    {
        return \count($this->raws);
    }

    public function matchedCount(): int
    {
        return $this->statusCounts['matched'] ?? 0;
    }

    public function openCount(): int
    {
        return ($this->statusCounts['unreviewed'] ?? 0) + ($this->statusCounts['needs_review'] ?? 0);
    }

    public function hasData(): bool
    {
        return [] !== $this->raws;
    }
}
