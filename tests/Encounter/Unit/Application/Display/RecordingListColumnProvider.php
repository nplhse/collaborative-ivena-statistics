<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Display;

use App\Encounter\Contract\Display\EncounterListCell;
use App\Encounter\Contract\Display\EncounterListColumn;
use App\Encounter\Contract\Display\EncounterListColumnProviderInterface;

/**
 * Encounter list column provider for registry tests: answers a cell for every requested column and encounter and
 * records the columns it was asked for.
 */
final class RecordingListColumnProvider implements EncounterListColumnProviderInterface
{
    /** @var list<string>|null columns asked for, null before the first call */
    public ?array $asked = null;

    /**
     * @param list<string> $keys
     */
    public function __construct(private readonly array $keys)
    {
    }

    #[\Override]
    public function columns(): array
    {
        return array_map(static fn (string $key): EncounterListColumn => new EncounterListColumn($key, 'label.'.$key, 'encounter'), $this->keys);
    }

    #[\Override]
    public function cellsForEncounters(array $encounterIds, array $columns): array
    {
        $this->asked = [...($this->asked ?? []), ...$columns];
        $cells = [];
        foreach ($columns as $column) {
            foreach ($encounterIds as $id) {
                $cells[$column][$id] = new EncounterListCell('@Test/cell.html.twig', ['id' => $id]);
            }
        }

        return $cells;
    }
}
