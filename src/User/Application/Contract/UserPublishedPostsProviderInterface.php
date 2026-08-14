<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

interface UserPublishedPostsProviderInterface
{
    public function countPublishedByUserId(int $userId): int;
}
