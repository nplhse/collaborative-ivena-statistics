<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Display;

use App\Encounter\Application\Display\EncounterListColumnRegistry;
use App\Encounter\Contract\Display\EncounterListColumn;
use PHPUnit\Framework\TestCase;

final class EncounterListColumnRegistryTest extends TestCase
{
    public function testEachProviderIsAskedOnceForItsOwnRequestedColumns(): void
    {
        $triage = $this->provider(['triage_category', 'triage_diagram']);
        $stay = $this->provider(['stay_ward']);
        $registry = new EncounterListColumnRegistry([$triage, $stay]);

        $cells = $registry->cells([7, 8], ['triage_diagram', 'unknown', 'triage_category', 'triage_category']);

        self::assertSame(['triage_diagram', 'triage_category'], $triage->asked);
        self::assertNull($stay->asked, 'A provider without a requested column is not asked.');
        self::assertSame(['triage_diagram', 'triage_category'], array_keys($cells));
        self::assertSame([7, 8], array_keys($cells['triage_category']));
        self::assertSame(['triage_category', 'triage_diagram', 'stay_ward'], array_keys($registry->columns()));
    }

    public function testNoEncountersAskNoProvider(): void
    {
        $provider = $this->provider(['triage_category']);

        self::assertSame([], new EncounterListColumnRegistry([$provider])->cells([], ['triage_category']));
        self::assertNull($provider->asked);
    }

    public function testColumnKeysMustBeUniqueAcrossSchemas(): void
    {
        $this->expectException(\LogicException::class);

        new EncounterListColumnRegistry([$this->provider(['triage_category']), $this->provider(['triage_category'])]);
    }

    public function testColumnKeysMustBeSimple(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncounterListColumn('Triage-Category', 'label', 'encounter');
    }

    /**
     * @param list<string> $keys
     */
    private function provider(array $keys): RecordingListColumnProvider
    {
        return new RecordingListColumnProvider($keys);
    }
}
