<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogAliasConflictException;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogReader;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogRenameConflictException;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameAliasSpec;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameCatalogGuard;
use App\Allocation\Application\ReferenceCatalog\ReferenceNameEntry;
use PHPUnit\Framework\TestCase;

final class ReferenceNameCatalogGuardTest extends TestCase
{
    public function testCommittedCatalogHasNoAliasCollisions(): void
    {
        $document = new ReferenceCatalogReader(\dirname(__DIR__, 5))->loadDefault();
        $guard = new ReferenceNameCatalogGuard();

        $guard->assertNoConflicts($document->departments);
        $guard->assertNoConflicts($document->specialities);

        $birthAliases = [];
        foreach ($document->departments as $entry) {
            if ('Geburtshilfe' === $entry->name) {
                $birthAliases = array_map(static fn (ReferenceNameAliasSpec $alias): string => $alias->name, $entry->aliases);
            }
        }
        self::assertSame([
            'Perinatalzentrum Level 1',
            'Perinatalzentrum Level 2',
            'Perinataler Schwerpunkt',
            'Geburtsklinik',
        ], $birthAliases);
    }

    public function testIsolationAliasCannotPointAtBaseDepartment(): void
    {
        $guard = new ReferenceNameCatalogGuard();

        $this->expectException(ReferenceCatalogAliasConflictException::class);
        $guard->assertNoConflicts([
            new ReferenceNameEntry('Plastische Chirurgie', [], [
                new ReferenceNameAliasSpec('Plastische Chirurgie - Isolierung', 'historical', 'local-catalog'),
            ]),
        ]);
    }

    public function testVentilationAliasCannotChangeSide(): void
    {
        $guard = new ReferenceNameCatalogGuard();

        $this->expectException(ReferenceCatalogAliasConflictException::class);
        $guard->assertNoConflicts([
            new ReferenceNameEntry('Innere IMC ohne Beatmung', [], [
                new ReferenceNameAliasSpec('Innere IMC mit Beatmung', 'historical', 'local-catalog'),
            ]),
        ]);
    }

    public function testRoundTripKeepsAliasMetadata(): void
    {
        $entry = ReferenceNameEntry::fromYaml([
            'name' => 'Allgemeine Innere Medizin',
            'previous_names' => ['Allgemein Innere Medizin', ''],
            'aliases' => [[
                'name' => 'Allgemein Innere Medizin',
                'classification' => 'faulty_catalog',
                'source' => 'local-catalog',
                'note' => 'catalog typo',
                'valid_from' => '2020-01-01',
                'valid_to' => '',
            ], 'skip-me'],
        ]);

        self::assertInstanceOf(ReferenceNameEntry::class, $entry);
        self::assertSame('Allgemeine Innere Medizin', $entry->name);
        self::assertSame(['Allgemein Innere Medizin'], $entry->previousNames);
        self::assertSame('faulty_catalog', $entry->aliases[0]->classification);
        self::assertSame('catalog typo', $entry->aliases[0]->note);
        self::assertSame('2020-01-01', $entry->aliases[0]->validFrom);
        self::assertNull($entry->aliases[0]->validTo);
        self::assertSame([
            'name' => 'Allgemeine Innere Medizin',
            'previous_names' => ['Allgemein Innere Medizin'],
            'aliases' => [[
                'name' => 'Allgemein Innere Medizin',
                'classification' => 'faulty_catalog',
                'source' => 'local-catalog',
                'note' => 'catalog typo',
                'valid_from' => '2020-01-01',
            ]],
        ], $entry->toYaml());
    }

    public function testBlankAndUnknownYamlItemsAreDropped(): void
    {
        self::assertNull(ReferenceNameEntry::fromYaml('   '));
        self::assertNull(ReferenceNameEntry::fromYaml(['name' => '']));
        self::assertNull(ReferenceNameEntry::fromYaml(1.5));
        self::assertSame('12', ReferenceNameEntry::fromYaml(12)?->name);
        self::assertSame('Kardiologie', ReferenceNameEntry::fromYaml('Kardiologie')?->toYaml());
    }

    public function testUnknownClassificationAndMissingSourceAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReferenceNameEntry::fromYaml([
            'name' => 'Geburtshilfe',
            'aliases' => [[
                'name' => 'Geburtsklinik',
                'classification' => 'guess',
                'source' => 'local-catalog',
            ]],
        ]);
    }

    public function testAliasWithoutSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReferenceNameEntry::fromYaml([
            'name' => 'Geburtshilfe',
            'aliases' => [[
                'name' => 'Geburtsklinik',
                'classification' => 'historical',
            ]],
        ]);
    }

    public function testDuplicateCanonicalAndSharedAliasAreRejected(): void
    {
        $guard = new ReferenceNameCatalogGuard();

        $this->expectException(ReferenceCatalogAliasConflictException::class);
        $guard->assertNoConflicts([
            new ReferenceNameEntry('Neurologie'),
            new ReferenceNameEntry(' Neurologie '),
        ]);
    }

    public function testAliasCannotRepeatItsCanonicalName(): void
    {
        $guard = new ReferenceNameCatalogGuard();

        $this->expectException(ReferenceCatalogAliasConflictException::class);
        $guard->assertNoConflicts([
            new ReferenceNameEntry('Neurologie', [], [
                new ReferenceNameAliasSpec('Neurologie', 'historical', 'local-catalog'),
            ]),
        ]);
    }

    public function testSameAliasCannotPointAtTwoCanonicalNames(): void
    {
        $guard = new ReferenceNameCatalogGuard();

        $this->expectException(ReferenceCatalogAliasConflictException::class);
        $guard->assertNoConflicts([
            new ReferenceNameEntry('Neurologie', [], [
                new ReferenceNameAliasSpec('Neuro', 'historical', 'local-catalog'),
            ]),
            new ReferenceNameEntry('Allgemeine Neurologie', [], [
                new ReferenceNameAliasSpec('Neuro', 'historical', 'local-catalog'),
            ]),
        ]);
    }

    public function testAliasCannotCollideWithAnotherCanonicalName(): void
    {
        $guard = new ReferenceNameCatalogGuard();

        $this->expectException(ReferenceCatalogAliasConflictException::class);
        $guard->assertNoConflicts([
            new ReferenceNameEntry('Neurologie'),
            new ReferenceNameEntry('Allgemeine Neurologie', [], [
                new ReferenceNameAliasSpec('Neurologie', 'historical', 'local-catalog'),
            ]),
        ]);
    }

    public function testPreviousNameCannotAlsoStayCanonical(): void
    {
        $guard = new ReferenceNameCatalogGuard();

        $this->expectException(ReferenceCatalogRenameConflictException::class);
        $guard->assertNoConflicts([
            new ReferenceNameEntry('Allgemein Innere Medizin'),
            new ReferenceNameEntry('Allgemeine Innere Medizin', ['Allgemein Innere Medizin']),
        ]);
    }
}
