<?php

declare(strict_types=1);

namespace App\Import\Infrastructure;

use App\Import\Domain\Entity\Import;

/**
 * Holds the importing user's id taken from the already-loaded Import.
 *
 * After EntityManager::clear(), allocations attach a getReference(Import) ghost.
 * Reading getCreatedBy() on that ghost initializes it and can flush NULL into import.name.
 */
final class ImportCreatedById
{
    private ?int $userId = null;

    public function captureFrom(Import $import): void
    {
        $this->userId = $import->getCreatedBy()?->getId();
    }

    public function userId(): ?int
    {
        return $this->userId;
    }
}
