<?php

declare(strict_types=1);

namespace App\Import\Application\Analysis\DTO;

final readonly class RejectAnalysisResult
{
    /**
     * @param list<RejectAnalysisGroup> $groups
     */
    public function __construct(
        public int $totalRejects,
        public array $groups,
    ) {
    }

    public function distinctGroupCount(): int
    {
        return \count($this->groups);
    }
}
