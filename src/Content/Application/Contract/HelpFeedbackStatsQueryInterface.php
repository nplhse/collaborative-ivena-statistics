<?php

declare(strict_types=1);

namespace App\Content\Application\Contract;

/** How many users found each help article helpful or not (#596), for the help administration. */
interface HelpFeedbackStatsQueryInterface
{
    /**
     * @return array<int, array{helpful: int, unhelpful: int}> by page id; articles without answers are missing
     */
    public function countsByPage(): array;
}
