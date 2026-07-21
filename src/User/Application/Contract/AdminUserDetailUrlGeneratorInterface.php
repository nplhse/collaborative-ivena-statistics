<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

interface AdminUserDetailUrlGeneratorInterface
{
    public function detailUrl(int $userId): string;
}
