<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

final readonly class TopListComparisonAssembler
{
    public function assemble(TopListRanking $rankingA, TopListRanking $rankingB): TopListComparison
    {
        $byIdentityA = $this->indexByIdentity($rankingA);
        $byIdentityB = $this->indexByIdentity($rankingB);

        $rowsA = [];
        foreach ($rankingA->rows as $rowA) {
            $rowsA[] = $this->join($rowA, $byIdentityB[$rowA->identity] ?? null);
        }

        $rowsB = [];
        foreach ($rankingB->rows as $rowB) {
            $rowsB[] = $this->join($byIdentityA[$rowB->identity] ?? null, $rowB);
        }

        return new TopListComparison(
            $rowsA,
            $rowsB,
            $rankingA->totalAllocations,
            $rankingB->totalAllocations,
            $rankingA->truncated || $rankingB->truncated,
        );
    }

    /**
     * @return array<string, TopListRankedRow>
     */
    private function indexByIdentity(TopListRanking $ranking): array
    {
        $byIdentity = [];
        foreach ($ranking->rows as $row) {
            $byIdentity[$row->identity] = $row;
        }

        return $byIdentity;
    }

    private function join(?TopListRankedRow $rowA, ?TopListRankedRow $rowB): TopListComparisonRow
    {
        $source = $rowA ?? $rowB;
        if (!$source instanceof TopListRankedRow) {
            throw new \InvalidArgumentException('Comparison join requires at least one ranked row.');
        }

        $onlyInA = $rowA instanceof TopListRankedRow && !$rowB instanceof TopListRankedRow;
        $onlyInB = $rowB instanceof TopListRankedRow && !$rowA instanceof TopListRankedRow;

        $deltaCount = null;
        $deltaShare = null;
        $rankMovement = null;
        if ($rowA instanceof TopListRankedRow && $rowB instanceof TopListRankedRow) {
            $deltaCount = $rowB->count - $rowA->count;
            $deltaShare = round($rowB->share - $rowA->share, 1);
            $rankMovement = $rowA->rank - $rowB->rank;
        } elseif ($rowB instanceof TopListRankedRow) {
            $deltaCount = $rowB->count;
            $deltaShare = $rowB->share;
        }

        return new TopListComparisonRow(
            $source->identity,
            $source->label,
            $rowA?->rank,
            $rowA?->count,
            $rowA?->share,
            $rowB?->rank,
            $rowB?->count,
            $rowB?->share,
            $deltaCount,
            $deltaShare,
            $rankMovement,
            $onlyInA,
            $onlyInB,
            $source->entityId,
        );
    }
}
