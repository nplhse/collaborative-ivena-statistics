<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

interface UserCreatedAtBackfillImportSourceInterface
{
    /**
     * @return array<int, \DateTimeImmutable> userId => earliest successful import createdAt
     */
    public function firstSuccessfulCreatedAtByUser(): array;

    /**
     * @return array<int, \DateTimeImmutable> hospitalId => earliest successful import createdAt
     */
    public function firstSuccessfulCreatedAtByHospital(): array;
}
