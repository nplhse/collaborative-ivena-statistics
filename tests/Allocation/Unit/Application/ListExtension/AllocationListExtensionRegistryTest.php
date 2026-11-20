<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ListExtension;

use App\Allocation\Application\ListExtension\AllocationListExtensionFilter;
use App\Allocation\Application\ListExtension\AllocationListExtensionInterface;
use App\Allocation\Application\ListExtension\AllocationListExtensionRegistry;
use App\Allocation\UI\Twig\AllocationListColumns;
use App\User\Domain\Entity\User;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class AllocationListExtensionRegistryTest extends TestCase
{
    public function testOnlyChoiceValuesOfAvailableExtensionsAreActive(): void
    {
        $registry = new AllocationListExtensionRegistry([
            $this->extension('encounter', available: true),
            $this->extension('hidden', available: false),
        ]);

        self::assertSame(
            ['encounter' => 'with'],
            $registry->activeFilters(['encounter' => 'with', 'hidden' => 'with', 'other' => 'x'], new User()),
        );
        self::assertSame([], $registry->activeFilters(['encounter' => 'maybe'], new User()));
        self::assertSame([], $registry->activeFilters(['encounter' => ['with']], new User()));
    }

    public function testAFilterValueCanShowItsColumn(): void
    {
        $registry = new AllocationListExtensionRegistry([$this->extension('encounter', available: true)]);

        self::assertSame(['encounter'], $registry->columnsShownBy(['encounter' => 'with'], new User()));
        self::assertSame([], $registry->columnsShownBy(['encounter' => 'without'], new User()));
    }

    public function testCellsAreAddedOnlyForVisibleColumns(): void
    {
        $registry = new AllocationListExtensionRegistry([$this->extension('encounter', available: true)]);
        $rows = [['id' => 7, 'hospital_id' => 3], ['id' => 8, 'hospital_id' => null]];

        self::assertSame(
            [['id' => 7, 'hospital_id' => 3, 'encounter' => 'encounter-7'], ['id' => 8, 'hospital_id' => null, 'encounter' => null]],
            $registry->withCells($rows, ['encounter'], new User()),
        );
        // Every visible column of an extension is answered by one call.
        self::assertSame(
            [['id' => 7, 'hospital_id' => 3, 'encounter' => 'encounter-7', 'encounter_detail' => 'encounter_detail-7'], ['id' => 8, 'hospital_id' => null, 'encounter' => null, 'encounter_detail' => null]],
            $registry->withCells($rows, ['encounter_detail', 'encounter'], new User()),
        );
        self::assertSame($rows, $registry->withCells($rows, ['timestamps'], new User()));
    }

    public function testExtensionColumnsComeBeforeTheRowActionAndOnlyForAvailableUsers(): void
    {
        $columns = new AllocationListColumns(new AllocationListExtensionRegistry([$this->extension('encounter', available: true)]));
        $hidden = new AllocationListColumns(new AllocationListExtensionRegistry([$this->extension('encounter', available: false)]));

        $keys = array_column($columns->columnsFor(new User()), 'key');
        self::assertSame(['encounter', 'encounter_detail', 'actions'], \array_slice($keys, -3));
        self::assertSame(['encounter', 'encounter_detail'], $columns->extensionColumnKeys(new User()));
        self::assertNotContains('encounter', array_column($hidden->columnsFor(new User()), 'key'));
        // The preference schema keeps the column for everyone, so a saved choice is not lost.
        self::assertContains('encounter', $hidden->preferenceSchema()->columnOrder);
    }

    public function testKeysMustBeUniqueAndSimple(): void
    {
        $this->expectException(\LogicException::class);

        new AllocationListExtensionRegistry([$this->extension('encounter', true), $this->extension('encounter', true)]);
    }

    public function testInvalidKeysAreRejected(): void
    {
        $this->expectException(\LogicException::class);

        new AllocationListExtensionRegistry([$this->extension('Encounter-Id', true)]);
    }

    private function extension(string $key, bool $available): AllocationListExtensionInterface
    {
        return new readonly class($key, $available) implements AllocationListExtensionInterface {
            public function __construct(private string $key, private bool $available)
            {
            }

            #[\Override]
            public function key(): string
            {
                return $this->key;
            }

            #[\Override]
            public function isAvailableFor(?User $user): bool
            {
                return $this->available;
            }

            #[\Override]
            public function filter(): AllocationListExtensionFilter
            {
                return new AllocationListExtensionFilter('label', 'all', ['with' => 'with', 'without' => 'without'], 'messages');
            }

            #[\Override]
            public function applyFilter(QueryBuilder $query, string $value, ?User $user): void
            {
            }

            #[\Override]
            public function columns(): array
            {
                return [
                    ['key' => $this->key, 'label' => 'label', 'type' => 'custom', 'visible' => false],
                    ['key' => $this->key.'_detail', 'label' => 'detail', 'type' => 'custom', 'visible' => false],
                ];
            }

            #[\Override]
            public function columnsShownFor(string $filterValue): array
            {
                return 'with' === $filterValue ? [$this->key] : [];
            }

            #[\Override]
            public function cellsFor(array $rows, array $visibleColumns, ?User $user): array
            {
                $cells = [];
                foreach ($visibleColumns as $column) {
                    $cells[$column] = [7 => $column.'-7'];
                }

                return $cells;
            }

            #[\Override]
            public function dataTables(): array
            {
                return ['encounter'];
            }
        };
    }
}
