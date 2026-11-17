<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\UI\Navigation;

use App\Allocation\Application\Explore\Overview\ExploreGroup;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Display\ExploreEntry;
use App\Encounter\Contract\Display\ExploreEntryProviderInterface;
use App\Encounter\UI\Navigation\SchemaExploreEntryForwarder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

final class SchemaExploreEntryForwarderTest extends TestCase
{
    public function testWithoutSchemaTilesNothingIsForwarded(): void
    {
        self::assertSame([], new SchemaExploreEntryForwarder([])->exploreEntries());
    }

    public function testSchemaTilesGoToTheCaseGroupAfterTheVisitListForCaseViewers(): void
    {
        $title = new TranslatableMessage('stays.title');
        $description = new TranslatableMessage('stays.description');
        $provider = new readonly class($title, $description) implements ExploreEntryProviderInterface {
            public function __construct(
                private TranslatableMessage $title,
                private TranslatableMessage $description,
            ) {
            }

            #[\Override]
            public function exploreEntries(): array
            {
                return [new ExploreEntry('stays', $this->title, $this->description, 'tabler:bed', 'app_stays', ['a' => 1], false, 3)];
            }
        };

        $entries = new SchemaExploreEntryForwarder([$provider])->exploreEntries();

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(
            ['stays', ExploreGroup::Cases, $title, $description, 'tabler:bed', 'app_stays', ['a' => 1], EncounterAttribute::VIEW_CASES_IN_ANY_HOSPITAL, false, 13],
            [$entry->key, $entry->group, $entry->title, $entry->description, $entry->icon, $entry->route, $entry->routeParameters, $entry->grantedAttribute, $entry->beta, $entry->weight],
        );
    }
}
