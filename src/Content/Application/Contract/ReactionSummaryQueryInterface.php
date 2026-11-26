<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

use App\Content\Application\Reaction\ReactionSummary;
use App\Content\Application\Reaction\ReactionTarget;

/** Reads the reactions of many targets at once, for one page (#467). */
interface ReactionSummaryQueryInterface
{
    /** Names shown per reaction in the tooltip; the rest is counted. */
    public const int MAX_NAMES = 10;

    /**
     * Reactions of disabled users are not counted, as their activities are not shown either.
     *
     * @param list<ReactionTarget> $targets
     * @param int|null             $viewerId  marks the viewer's own reaction; null for visitors
     * @param bool                 $withNames whether to return the names for the tooltip (signed-in viewers only)
     *
     * @return array<string, ReactionSummary> by {@see ReactionTarget::key()}, one entry per target
     */
    public function summaries(array $targets, ?int $viewerId, bool $withNames): array;
}
