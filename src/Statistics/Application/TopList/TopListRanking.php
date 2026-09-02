<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final readonly class TopListRanking
{
    public const string UNKNOWN_SENTINEL = 'Unknown';

    public const string UNKNOWN_TRANSLATION_KEY = 'stats.top_lists.unknown';

    /**
     * @param list<TopListRankedRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $totalAllocations,
        public bool $truncated = false,
    ) {
    }

    /**
     * @param list<array{label: string, count: int, entityId?: ?int, indicationId?: ?int}> $rows
     */
    public static function fromAggregates(array $rows, int $totalAllocations, int $fetchLimit, string $unknownLabel = self::UNKNOWN_SENTINEL): self
    {
        $truncated = $fetchLimit > TopListLimit::ALL_SAFETY_CAP && \count($rows) > TopListLimit::ALL_SAFETY_CAP;
        if ($truncated) {
            $rows = \array_slice($rows, 0, TopListLimit::ALL_SAFETY_CAP);
        }

        $ranked = [];
        $rank = 1;
        foreach ($rows as $row) {
            $count = $row['count'];
            $entityId = $row['entityId'] ?? $row['indicationId'] ?? null;
            $ranked[] = new TopListRankedRow(
                null !== $entityId ? (string) $entityId : 'unknown',
                self::localizeLabel($row['label'], $unknownLabel),
                $count,
                $totalAllocations > 0 ? round(100 * $count / $totalAllocations, 1) : 0.0,
                $rank,
                $entityId,
            );
            ++$rank;
        }

        return new self($ranked, $totalAllocations, $truncated);
    }

    public static function localizeLabel(string $label, string $unknownLabel): string
    {
        return '' === $label || self::UNKNOWN_SENTINEL === $label ? $unknownLabel : $label;
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    public function pageSlice(int $page, int $pageSize): self
    {
        if ($pageSize < 1 || [] === $this->rows) {
            return $this;
        }

        $lastPage = max(1, (int) ceil(\count($this->rows) / $pageSize));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $pageSize;

        return new self(
            \array_slice($this->rows, $offset, $pageSize),
            $this->totalAllocations,
            $this->truncated,
        );
    }
}
