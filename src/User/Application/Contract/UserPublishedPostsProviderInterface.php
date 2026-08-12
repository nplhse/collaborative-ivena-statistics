<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Application\Explore\UserPublishedPostSummary;

interface UserPublishedPostsProviderInterface
{
    /**
     * @return list<UserPublishedPostSummary>
     */
    public function findPublishedByUserId(int $userId, int $limit = 10): array;
}
