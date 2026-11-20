<?php

declare(strict_types=1);

namespace App\Encounter\Application\Display;

use App\Encounter\Contract\Display\EncounterListCell;
use App\Encounter\Contract\Display\EncounterListColumn;
use App\Encounter\Contract\Display\EncounterListColumnProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Columns of every encounter schema for the Explore allocation list (extension point `encounter.list_column`, #1203).
 * Callers check VIEW_CASES on the encounters' hospitals before they ask for cells.
 */
final readonly class EncounterListColumnRegistry
{
    /** @var array<string, EncounterListColumn> */
    private array $columns;

    /** @var array<string, EncounterListColumnProviderInterface> provider by column key */
    private array $providers;

    /**
     * @param iterable<EncounterListColumnProviderInterface> $providers
     *
     * @psalm-suppress PossiblyUnusedMethod Autowired.
     */
    public function __construct(
        #[AutowireIterator(EncounterListColumnProviderInterface::TAG)]
        iterable $providers,
    ) {
        $columns = [];
        $byColumn = [];
        foreach ($providers as $provider) {
            foreach ($provider->columns() as $column) {
                if (isset($columns[$column->key])) {
                    throw new \LogicException(\sprintf('Duplicate encounter list column "%s".', $column->key));
                }
                $columns[$column->key] = $column;
                $byColumn[$column->key] = $provider;
            }
        }
        $this->columns = $columns;
        $this->providers = $byColumn;
    }

    /**
     * @return array<string, EncounterListColumn>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Cells of the given columns; unknown column keys are ignored, each provider is asked once.
     *
     * @param list<int>    $encounterIds
     * @param list<string> $columnKeys
     *
     * @return array<string, array<int, EncounterListCell>> column key => encounter id => cell
     */
    public function cells(array $encounterIds, array $columnKeys): array
    {
        if ([] === $encounterIds) {
            return [];
        }

        $byProvider = [];
        foreach (array_unique($columnKeys) as $key) {
            if (isset($this->providers[$key])) {
                $byProvider[spl_object_id($this->providers[$key])][] = $key;
            }
        }

        $cells = [];
        foreach ($byProvider as $keys) {
            $provider = $this->providers[$keys[0]];
            foreach ($provider->cellsForEncounters($encounterIds, $keys) as $column => $byEncounter) {
                if (\in_array($column, $keys, true)) {
                    $cells[$column] = $byEncounter;
                }
            }
        }

        return $cells;
    }
}
