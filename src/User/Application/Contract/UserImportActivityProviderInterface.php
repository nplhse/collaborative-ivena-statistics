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

    /**
     * @param list<int> $userIds
     *
     * @return array<int, \DateTimeImmutable|null> userId => last successful import timestamp
     */
    public function lastSuccessfulAtByUserIds(array $userIds): array;
}
