<?php

declare(strict_types=1);

namespace App\User\Application\Activity;

final class UserActivityBackfillReport
{
    public int $inspected = 0;
    public int $joined = 0;
    public int $milestones = 0;
    public int $posts = 0;
    public int $comments = 0;
    public int $hospitalAssociated = 0;
    public int $hospitalOwner = 0;
    public int $skippedExisting = 0;
    public int $unableToReconstruct = 0;

    public function recordedTotal(): int
    {
        return $this->joined
            + $this->milestones
            + $this->posts
            + $this->comments
            + $this->hospitalAssociated
            + $this->hospitalOwner;
    }
}
