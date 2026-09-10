<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

interface AllocationPersisterInterface
{
    public function persist(object $entity): void;

    public function flush(): void;

    /** Drop identity-map catalog entities so flush cannot UPDATE them to NULL. */
    public function clear(): void;
}
