<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Display;

use App\Encounter\Application\Display\EncounterLinkRegistry;
use App\Encounter\Contract\Display\EncounterLinkProviderInterface;
use PHPUnit\Framework\TestCase;

final class EncounterLinkRegistryTest extends TestCase
{
    public function testWithoutProvidersNoEncounterHasALink(): void
    {
        self::assertSame([], new EncounterLinkRegistry([])->forEncounters([1, 2]));
    }

    public function testTheFirstLinkWinsAndLaterProvidersAreAskedOnlyForTheRest(): void
    {
        /** @var \ArrayObject<int, list<int>> $asked */
        $asked = new \ArrayObject();
        $registry = new EncounterLinkRegistry([$this->provider([1 => '/a/1', 2 => '/a/2']), $this->provider([2 => '/b/2', 3 => '/b/3'], $asked)]);

        self::assertSame([1 => '/a/1', 2 => '/a/2', 3 => '/b/3'], $registry->forEncounters([1, 2, 3, 4]));
        self::assertSame([[3, 4]], $asked->getArrayCopy());
    }

    public function testNoProviderIsAskedOnceEveryEncounterHasALink(): void
    {
        /** @var \ArrayObject<int, list<int>> $asked */
        $asked = new \ArrayObject();
        new EncounterLinkRegistry([$this->provider([1 => '/a/1']), $this->provider([], $asked)])->forEncounters([1]);

        self::assertCount(0, $asked);
    }

    /**
     * @param array<int, string>                $links
     * @param \ArrayObject<int, list<int>>|null $asked the ids of each call
     */
    private function provider(array $links, ?\ArrayObject $asked = null): EncounterLinkProviderInterface
    {
        return new readonly class($links, $asked ?? new \ArrayObject()) implements EncounterLinkProviderInterface {
            /**
             * @param array<int, string>           $links
             * @param \ArrayObject<int, list<int>> $asked
             */
            public function __construct(
                private array $links,
                private \ArrayObject $asked,
            ) {
            }

            #[\Override]
            public function linksForEncounters(array $encounterIds): array
            {
                $this->asked[] = $encounterIds;

                return array_intersect_key($this->links, array_flip($encounterIds));
            }
        };
    }
}
