<?php

declare(strict_types=1);

namespace App\Allocation\Application\ListExtension;

use App\User\Domain\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The filters and columns other contexts add to the Explore allocation list (extension point
 * `allocation.list_extension`, #1203).
 */
final readonly class AllocationListExtensionRegistry
{
    /** @var array<string, AllocationListExtensionInterface> */
    private array $extensions;

    /**
     * @param iterable<AllocationListExtensionInterface> $extensions
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(AllocationListExtensionInterface::TAG)]
        iterable $extensions,
    ) {
        $byKey = [];
        foreach ($extensions as $extension) {
            $key = $extension->key();
            if (1 !== preg_match('/^[a-z][a-z_]*$/', $key)) {
                throw new \LogicException(\sprintf('Invalid allocation list extension key "%s".', $key));
            }
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('Duplicate allocation list extension key "%s".', $key));
            }
            $byKey[$key] = $extension;
        }
        $this->extensions = $byKey;
    }

    /**
     * @return array<string, AllocationListExtensionInterface>
     */
    public function availableFor(?User $user): array
    {
        return array_filter($this->extensions, static fn (AllocationListExtensionInterface $extension): bool => $extension->isAvailableFor($user));
    }

    /**
     * The valid extension filter values of a request query, keyed by extension key.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, string>
     */
    public function activeFilters(array $query, ?User $user): array
    {
        $active = [];
        foreach ($this->availableFor($user) as $key => $extension) {
            $value = $query[$key] ?? null;
            if (\is_string($value) && \array_key_exists($value, $extension->filter()->choices)) {
                $active[$key] = $value;
            }
        }

        return $active;
    }

    /**
     * @param array<string, string> $activeFilters from activeFilters()
     */
    public function applyFilters(QueryBuilder $query, array $activeFilters, ?User $user): void
    {
        foreach ($this->availableFor($user) as $key => $extension) {
            if (isset($activeFilters[$key])) {
                $extension->applyFilter($query, $activeFilters[$key], $user);
            }
        }
    }

    /**
     * Columns of the extensions available to the user.
     *
     * @return list<array<string, mixed>>
     */
    public function columnsFor(?User $user): array
    {
        return array_values(array_map(
            static fn (AllocationListExtensionInterface $extension): array => $extension->column(),
            $this->availableFor($user),
        ));
    }

    /**
     * Every extension column, for the preference schema that is the same for all users.
     *
     * @return list<array<string, mixed>>
     */
    public function allColumns(): array
    {
        return array_values(array_map(
            static fn (AllocationListExtensionInterface $extension): array => $extension->column(),
            $this->extensions,
        ));
    }

    /**
     * Columns shown without being picked because of an active filter value.
     *
     * @param array<string, string> $activeFilters
     *
     * @return list<string>
     */
    public function columnsShownBy(array $activeFilters, ?User $user): array
    {
        $keys = [];
        foreach ($this->availableFor($user) as $key => $extension) {
            if (isset($activeFilters[$key]) && $extension->showsColumnFor($activeFilters[$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Adds the cell values of the visible extension columns to the rows of one page.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $visibleColumns
     *
     * @return list<array<string, mixed>>
     */
    public function withCells(array $rows, array $visibleColumns, ?User $user): array
    {
        if ([] === $rows) {
            return $rows;
        }

        $keys = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'hospital_id' => isset($row['hospital_id']) ? (int) $row['hospital_id'] : null,
        ], $rows);

        foreach ($this->availableFor($user) as $key => $extension) {
            if (!\in_array($key, $visibleColumns, true)) {
                continue;
            }
            $cells = $extension->cellsFor($keys, $user);
            $rows = array_map(static fn (array $row): array => [...$row, $key => $cells[(int) $row['id']] ?? null], $rows);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function dataTables(): array
    {
        $tables = [];
        foreach ($this->extensions as $extension) {
            array_push($tables, ...$extension->dataTables());
        }

        return array_values(array_unique($tables));
    }
}
