<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final readonly class TopListComparison
{
    /**
     * @param list<TopListComparisonRow> $rowsA
     * @param list<TopListComparisonRow> $rowsB
     */
    public function __construct(
        public array $rowsA,
        public array $rowsB,
        public int $totalAllocationsA,
        public int $totalAllocationsB,
        public bool $truncated = false,
    ) {
    }

    public function count(): int
    {
        return max(\count($this->rowsA), \count($this->rowsB));
    }

    /**
     * Full outer join of both rankings: all A identities first, then values that exist only in B.
     *
     * @return list<TopListComparisonRow>
     */
    public function mergedRows(): array
    {
        $merged = $this->rowsA;
        $identities = [];
        foreach ($this->rowsA as $row) {
            $identities[$row->identity] = true;
        }

        foreach ($this->rowsB as $row) {
            if (!isset($identities[$row->identity])) {
                $merged[] = $row;
            }
        }

        return $merged;
    }

    public function pageSlice(int $page, int $pageSize): self
    {
        return new self(
            $this->slice($this->rowsA, $page, $pageSize),
            $this->slice($this->rowsB, $page, $pageSize),
            $this->totalAllocationsA,
            $this->totalAllocationsB,
            $this->truncated,
        );
    }

    /**
     * @param list<TopListComparisonRow> $rows
     *
     * @return list<TopListComparisonRow>
     */
    private function slice(array $rows, int $page, int $pageSize): array
    {
        if ($pageSize < 1 || [] === $rows) {
            return $rows;
        }

        $page = max(1, $page);
        $offset = ($page - 1) * $pageSize;

        return \array_slice($rows, $offset, $pageSize);
    }
}
