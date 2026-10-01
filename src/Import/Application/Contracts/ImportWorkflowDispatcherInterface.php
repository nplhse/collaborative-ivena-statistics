<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

interface ImportWorkflowDispatcherInterface
{
    public function dispatch(int $importId): void;
}
