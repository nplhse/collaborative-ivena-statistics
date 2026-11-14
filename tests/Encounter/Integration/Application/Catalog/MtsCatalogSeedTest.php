<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Application\Catalog;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The migration seeds the MTS catalog with the content of mts-de.json (ADR 032, #1082): 40 diagrams and
 * 70 indicators, curated, source orbis_export, codes derived like the tool, English names for 39 diagrams, no
 * aliases and no assignments.
 */
#[ResetDatabase]
final class MtsCatalogSeedTest extends KernelTestCase
{
    public function testSeedHoldsTheBundledCatalog(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(MtsCatalog::class);
        $data = MtsCatalog::bundledData();
        self::assertIsArray($data['diagrams']);
        self::assertIsArray($data['indicators']);

        self::assertCount(40, $catalog->diagrams());
        self::assertCount(70, $catalog->indicators());
        self::assertSame($this->codes($data['diagrams']), array_keys($catalog->diagrams()));
        self::assertSame($this->codes($data['indicators']), array_keys($catalog->indicators()));
        foreach ([...$catalog->diagrams(), ...$catalog->indicators()] as $code => $entry) {
            self::assertSame((string) $code, MtsCatalog::code($entry->germanName));
        }

        self::assertEquals(new MtsCatalogEntry('thoraxschmerz', 'Thoraxschmerz', 'Chest pain'), $catalog->diagram('thoraxschmerz'));
        self::assertEquals(new MtsCatalogEntry('stuerze', 'Stürze', 'Falls'), $catalog->diagram('stuerze'));
        self::assertEquals(new MtsCatalogEntry('generelle_indikatoren', 'Generelle Indikatoren'), $catalog->diagram('generelle_indikatoren'));
        self::assertEquals(new MtsCatalogEntry('heiss', 'Heiß'), $catalog->indicator('heiss'));
        self::assertNull($catalog->diagram('unknown'));
    }

    public function testSeedRowsAreCuratedOrbisExportEntriesWithoutAliasesOrAssignments(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $summary = static fn (string $table): array => $connection->fetchAllAssociative(\sprintf(
            'SELECT source, status, COUNT(*) AS entries, COUNT(english_name) AS english, COUNT(first_seen_at) AS first_seen FROM %s GROUP BY source, status',
            $table,
        ));

        self::assertSame([['source' => 'orbis_export', 'status' => 'curated', 'entries' => 40, 'english' => 39, 'first_seen' => 0]], $summary('encounter_mts_diagram'));
        self::assertSame([['source' => 'orbis_export', 'status' => 'curated', 'entries' => 70, 'english' => 0, 'first_seen' => 0]], $summary('encounter_mts_indicator'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter_mts_alias'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM encounter_mts_diagram_indicator'));
    }

    /**
     * @param array<mixed> $labels
     *
     * @return list<string>
     */
    private function codes(array $labels): array
    {
        $codes = array_map(static fn (mixed $label): string => MtsCatalog::code((string) $label), $labels);
        sort($codes, \SORT_STRING);

        return $codes;
    }
}
