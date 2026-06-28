<?php

declare(strict_types=1);

namespace App\Statistics\Application\Contract;

interface MaterializedViewRefresherInterface
{
    /**
     * @param list<string> $groups empty = all registered groups
     *
     * @return list<array{view: string, success: bool, message: string}>
     */
    public function refresh(array $groups = [], ?bool $concurrently = null): array;
}
