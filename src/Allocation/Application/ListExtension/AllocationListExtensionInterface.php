<?php

declare(strict_types=1);

namespace App\Allocation\Application\ListExtension;

use App\User\Domain\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point for a filter and a column of the Explore allocation list (`/explore/allocation`, #1203).
 *
 * Other contexts add their own data to the list (for example whether an allocation has an encounter) without a
 * dependency from Allocation on them. An extension owns one query parameter, named by key(), and one or more columns.
 * It decides itself who may use it and must check access per row: the list spans hospitals, and data of a
 * hospital the user may not see must neither appear in a cell nor narrow the rows by a filter on it.
 */
#[AutoconfigureTag(self::TAG)]
interface AllocationListExtensionInterface
{
    public const string TAG = 'allocation.list_extension';

    /**
     * Query parameter of the filter; lowercase letters and underscores.
     */
    public function key(): string;

    /**
     * Whether the user may see the filter and the column at all.
     */
    public function isAvailableFor(?User $user): bool;

    public function filter(): AllocationListExtensionFilter;

    /**
     * Narrows the list query (root alias `a`, hospital alias `h`). Called only for available users and with one
     * of the filter's choice values.
     */
    public function applyFilter(QueryBuilder $query, string $value, ?User $user): void;

    /**
     * The DataTable columns (see DataTableColumns), keys unique across the list; a cell template reads
     * `row[<column key>]`.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(): array;

    /**
     * Keys of the columns shown without being picked while the filter has this value.
     *
     * @return list<string>
     */
    public function columnsShownFor(string $filterValue): array;

    /**
     * Cell values of the visible columns for the rows of one page: column key => allocation id => value. Rows of
     * hospitals the user may not see get no value. Called once per page with at least one visible column of the
     * extension; a few queries per page, never one per row.
     *
     * @param list<array{id: int, hospital_id: int|null}> $rows
     * @param list<string>                                $visibleColumns keys of this extension's visible columns
     *
     * @return array<string, array<int, mixed>>
     */
    public function cellsFor(array $rows, array $visibleColumns, ?User $user): array;

    /**
     * Tables whose writes can change the filter result; the cached result count starts again after a write.
     *
     * @return list<string>
     */
    public function dataTables(): array;
}
