<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Display;

use App\Encounter\Application\Display\EncounterListDetailRegistry;
use App\Encounter\Contract\Display\EncounterListDetail;
use App\Encounter\Contract\Display\EncounterListDetailProviderInterface;
use PHPUnit\Framework\TestCase;

final class EncounterListDetailRegistryTest extends TestCase
{
    public function testDetailsOfEverySchemaAreCollectedPerEncounterInProviderOrder(): void
    {
        $registry = new EncounterListDetailRegistry([
            $this->provider([7 => new EncounterListDetail('@A/a.html.twig')]),
            $this->provider([7 => new EncounterListDetail('@B/b.html.twig'), 8 => new EncounterListDetail('@B/b.html.twig')]),
        ]);

        $details = $registry->forEncounters([7, 8, 9]);

        self::assertSame([7, 8], array_keys($details));
        self::assertSame(['@A/a.html.twig', '@B/b.html.twig'], array_map(static fn (EncounterListDetail $detail): string => $detail->template, $details[7]));
        self::assertCount(1, $details[8]);
    }

    public function testNoEncountersAskNoProvider(): void
    {
        $provider = $this->createMock(EncounterListDetailProviderInterface::class);
        $provider->expects(self::never())->method('detailsForEncounters');

        self::assertSame([], new EncounterListDetailRegistry([$provider])->forEncounters([]));
    }

    /**
     * @param array<int, EncounterListDetail> $details
     */
    private function provider(array $details): EncounterListDetailProviderInterface
    {
        return new readonly class($details) implements EncounterListDetailProviderInterface {
            /**
             * @param array<int, EncounterListDetail> $details
             */
            public function __construct(private array $details)
            {
            }

            #[\Override]
            public function detailsForEncounters(array $encounterIds): array
            {
                return array_intersect_key($this->details, array_flip($encounterIds));
            }
        };
    }
}
