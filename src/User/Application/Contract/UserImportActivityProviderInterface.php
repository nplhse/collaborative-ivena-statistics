<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

interface UserImportActivityProviderInterface
{
    /**
     * @param list<int> $userIds
     *
     * @return array<int, int> userId => successful import count (COMPLETED|PARTIAL)
     */
    public function countsByUserIds(array $userIds): array;
}
