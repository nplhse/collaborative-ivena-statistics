<?php

namespace App\Service\Import\Contracts;

interface AllocationPersisterInterface
{
    public function persist(object $entity): void;

    public function flush(): void;
}
