<?php

declare(strict_types=1);

namespace App\Shared\Application\DataTable;

use App\Shared\Domain\Entity\DataTablePreference;
use App\Shared\Infrastructure\Repository\DataTablePreferenceRepository;
use App\User\Domain\Entity\User;

final readonly class DataTablePreferenceService
{
    /** @psalm-suppress PossiblyUnusedMethod Instantiated by the dependency injection container. */
    public function __construct(
        private DataTablePreferenceRepository $repository,
        private DataTablePreferenceDefinitionRegistry $registry,
    ) {
    }

    /**
     * Explicit values originate from the current URL and override stored defaults.
     *
     * @param list<string>|null $visibleColumns
     * @param list<string>|null $columnOrder
     */
    public function resolve(
        ?User $user,
        DataTablePreferenceSchema $schema,
        ?array $visibleColumns = null,
        ?array $columnOrder = null,
        ?int $pageSize = null,
    ): DataTablePreferenceState {
        $configuration = [];
        if ($user instanceof User) {
            $configuration = $this->repository
                ->findForUserAndTable($user, $schema->key)
                ?->getConfiguration() ?? [];
        }

        $state = $this->reconcile($schema, $configuration);
        $resolvedOrder = null === $columnOrder
            ? $state->columnOrder
            : $this->completeOrder($columnOrder, $state->columnOrder);
        $resolvedVisible = null === $visibleColumns
            ? $state->visibleColumns
            : $this->visibleColumns($visibleColumns, $schema);
        $resolvedPageSize = null !== $pageSize && \in_array($pageSize, $schema->pageSizes, true)
            ? $pageSize
            : $state->pageSize;

        return new DataTablePreferenceState($resolvedVisible, $resolvedOrder, $resolvedPageSize);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function save(User $user, string $tableKey, array $configuration): void
    {
        $schema = $this->registry->get($tableKey);
        if (!$schema instanceof DataTablePreferenceSchema) {
            throw new \InvalidArgumentException(sprintf('Unknown DataTable preference key "%s".', $tableKey));
        }

        $state = $this->reconcile($schema, $configuration);
        $preference = $this->repository->findForUserAndTable($user, $tableKey);
        if (!$preference instanceof DataTablePreference) {
            $preference = new DataTablePreference($user, $tableKey, $state->toArray());
        } else {
            $preference->update($state->toArray());
        }
        $this->repository->save($preference);
    }

    public function reset(User $user, string $tableKey): void
    {
        if (!$this->registry->get($tableKey) instanceof DataTablePreferenceSchema) {
            throw new \InvalidArgumentException(sprintf('Unknown DataTable preference key "%s".', $tableKey));
        }

        $preference = $this->repository->findForUserAndTable($user, $tableKey);
        if ($preference instanceof DataTablePreference) {
            $this->repository->remove($preference);
        }
    }

    public function resetColumns(User $user, string $tableKey): void
    {
        $schema = $this->registry->get($tableKey);
        if (!$schema instanceof DataTablePreferenceSchema) {
            throw new \InvalidArgumentException(sprintf('Unknown DataTable preference key "%s".', $tableKey));
        }

        $preference = $this->repository->findForUserAndTable($user, $tableKey);
        if (!$preference instanceof DataTablePreference) {
            return;
        }

        $current = $this->reconcile($schema, $preference->getConfiguration());
        $defaults = $schema->defaults();
        $preference->update(new DataTablePreferenceState(
            $defaults->visibleColumns,
            $defaults->columnOrder,
            $current->pageSize,
        )->toArray());
        $this->repository->save($preference);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function reconcile(DataTablePreferenceSchema $schema, array $configuration): DataTablePreferenceState
    {
        $storedOrder = $this->stringList($configuration['columnOrder'] ?? null);
        $order = $this->completeOrder($storedOrder, $schema->columnOrder);

        $storedVisible = $this->stringList($configuration['visibleColumns'] ?? null);
        $visible = [] === $storedVisible && !\array_key_exists('visibleColumns', $configuration)
            ? $schema->defaultVisibleColumns
            : $storedVisible;

        // Columns absent from an older stored order are newly introduced and use current defaults.
        foreach ($schema->columnOrder as $key) {
            /** @psalm-suppress RedundantCondition Stored state may originate from older definitions. */
            if (!\in_array($key, $storedOrder, true)
                && \in_array($key, $schema->defaultVisibleColumns, true)
                && !\in_array($key, $visible, true)
            ) {
                $visible[] = $key;
            }
        }

        $pageSize = $configuration['pageSize'] ?? $schema->defaultPageSize;
        if (!\is_int($pageSize) || !\in_array($pageSize, $schema->pageSizes, true)) {
            $pageSize = $schema->defaultPageSize;
        }

        return new DataTablePreferenceState(
            $this->visibleColumns($visible, $schema),
            $order,
            $pageSize,
        );
    }

    /**
     * @param list<string> $preferred
     * @param list<string> $fallback
     *
     * @return list<string>
     */
    private function completeOrder(array $preferred, array $fallback): array
    {
        $available = array_fill_keys($fallback, true);
        $order = [];
        foreach ([...$preferred, ...$fallback] as $key) {
            if (isset($available[$key]) && !\in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        return $order;
    }

    /**
     * @param list<string> $visible
     *
     * @return list<string>
     */
    private function visibleColumns(array $visible, DataTablePreferenceSchema $schema): array
    {
        $available = array_fill_keys($schema->columnOrder, true);
        $resolved = [];
        foreach ([...$visible, ...$schema->requiredColumns] as $key) {
            if (isset($available[$key]) && !\in_array($key, $resolved, true)) {
                $resolved[] = $key;
            }
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== $item && !\in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
