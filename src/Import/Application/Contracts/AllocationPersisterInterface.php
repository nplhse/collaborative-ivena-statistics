<?php

namespace App\Import\Application\Contracts;

interface AllocationPersisterInterface
{
    public function persist(object $entity): void;

    public function flush(): void;
}
