<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

interface UserPublishedCommentsProviderInterface
{
    public function countOnPublishedPostsByUserId(int $userId): int;
}
