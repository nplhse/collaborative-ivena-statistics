<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore;

use App\Allocation\Application\Explore\Overview\ExploreEntry;
use App\Allocation\Application\Explore\Overview\ExploreEntryProviderInterface;
use App\Allocation\Application\Explore\Overview\ExploreEntryRegistry;
use App\Allocation\Application\Explore\Overview\ExploreGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

final class ExploreEntryRegistryTest extends TestCase
{
    public function testGroupsEntriesOfAllProvidersOrderedByWeightThenKey(): void
    {
        $registry = new ExploreEntryRegistry([
            $this->provider([$this->entry('b', ExploreGroup::Cases, 10), $this->entry('people', ExploreGroup::Organisation)]),
            $this->provider([$this->entry('c', ExploreGroup::Cases), $this->entry('a', ExploreGroup::Cases, 10)]),
        ]);

        $byGroup = $registry->byGroup();

        self::assertSame(['cases', 'organisation'], array_keys($byGroup));
        self::assertSame(['c', 'a', 'b'], array_map(static fn (ExploreEntry $entry): string => $entry->key, $byGroup['cases']));
        self::assertSame(['people'], array_map(static fn (ExploreEntry $entry): string => $entry->key, $byGroup['organisation']));
    }

    public function testAKeyCanBeContributedOnce(): void
    {
        $registry = new ExploreEntryRegistry([
            $this->provider([$this->entry('a', ExploreGroup::Cases)]),
            $this->provider([$this->entry('a', ExploreGroup::Features)]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Explore entry "a" is contributed twice.');

        $registry->byGroup();
    }

    private function entry(string $key, ExploreGroup $group, int $weight = 0): ExploreEntry
    {
        return new ExploreEntry($key, $group, new TranslatableMessage($key), new TranslatableMessage($key), 'tabler:list', 'app_explore_index', null, weight: $weight);
    }

    /**
     * @param list<ExploreEntry> $entries
     */
    private function provider(array $entries): ExploreEntryProviderInterface
    {
        return new readonly class($entries) implements ExploreEntryProviderInterface {
            /**
             * @param list<ExploreEntry> $entries
             */
            public function __construct(private array $entries)
            {
            }

            #[\Override]
            public function exploreEntries(): array
            {
                return $this->entries;
            }
        };
    }
}
