<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Import;

use App\Allocation\Application\ReferenceCatalog\Package\AliasEntry;
use App\Allocation\Application\ReferenceCatalog\Package\CatalogEntry;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogViolation;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;

/**
 * Collects the decisions of the import planner.
 *
 * @internal used by ReferenceCatalogImportPlanner only
 */
final class ImportPlanBuilder
{
    /** @var list<PlannedRow> */
    private array $rows = [];

    /** @var list<PlannedAlias> */
    private array $aliases = [];

    /** @var array<string, array{create: int, skip: int}> */
    private array $counts = [];

    /** @var list<ReferenceCatalogViolation> */
    private array $conflicts = [];

    public function startType(ReferenceCatalogType $type): void
    {
        $this->counts[$type->value] = ['create' => 0, 'skip' => 0];
    }

    public function create(ReferenceCatalogType $type, CatalogEntry $entry): void
    {
        $this->rows[] = new PlannedRow($type, $entry);
        ++$this->counts[$type->value]['create'];
    }

    public function skip(ReferenceCatalogType $type): void
    {
        ++$this->counts[$type->value]['skip'];
    }

    public function createAlias(ReferenceCatalogType $ownerType, string $ownerId, AliasEntry $alias): void
    {
        $this->aliases[] = new PlannedAlias($ownerType, $ownerId, $alias);
        $this->countAlias($ownerType, 'create');
    }

    public function skipAlias(ReferenceCatalogType $ownerType): void
    {
        $this->countAlias($ownerType, 'skip');
    }

    public function conflict(string $path, string $message): void
    {
        $this->conflicts[] = new ReferenceCatalogViolation($path, $message);
    }

    public function build(): ReferenceCatalogImportPlan
    {
        return new ReferenceCatalogImportPlan($this->rows, $this->aliases, $this->counts, $this->conflicts);
    }

    /**
     * @param 'create'|'skip' $action
     */
    private function countAlias(ReferenceCatalogType $ownerType, string $action): void
    {
        $key = $ownerType->value.'-alias';
        $this->counts[$key] ??= ['create' => 0, 'skip' => 0];
        ++$this->counts[$key][$action];
    }
}
