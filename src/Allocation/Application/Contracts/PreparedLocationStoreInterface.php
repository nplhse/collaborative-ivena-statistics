<?php

declare(strict_types=1);

namespace App\Allocation\Application\Contracts;

use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;

/**
 * Local library of prepared locations, keyed by slug (#791).
 */
interface PreparedLocationStoreInterface
{
    public function find(string $slug): ?PreparedLocation;

    public function exists(string $slug): bool;

    public function save(PreparedLocation $location): void;

    /**
     * @return list<PreparedLocation> sorted by slug; unreadable files are left out
     */
    public function all(): array;
}
