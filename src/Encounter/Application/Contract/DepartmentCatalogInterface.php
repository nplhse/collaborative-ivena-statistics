<?php

declare(strict_types=1);

namespace App\Encounter\Application\Contract;

use App\Allocation\Domain\Entity\Department;

/**
 * Resolves `admitting_department` of the exchange format: the name of a department of the reference
 * catalog (ADR 020), as the hospital's local map in the data preparation tool writes it.
 */
interface DepartmentCatalogInterface
{
    /**
     * The department with this name (case-insensitive), or null when the catalog has none.
     */
    public function findByName(string $name): ?Department;
}
