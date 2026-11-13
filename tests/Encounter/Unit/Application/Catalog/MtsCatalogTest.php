<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Catalog;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The MTS catalog is one file for the data preparation tool and the MTS glossary (#1056). Codes must
 * be the ones the tool writes, so the glossary finds the codes stored by the import.
 */
final class MtsCatalogTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../DataPrep/fixtures';
    private const string TRANSLATIONS = __DIR__.'/../../../../../translations';

    public function testCatalogListsTheDiagramsAndIndicatorsOfTheToolCatalog(): void
    {
        $catalog = new MtsCatalog();
        $data = $catalog->data();

        self::assertSame(MtsCatalog::ID, $data['id']);
        self::assertIsArray($data['diagrams']);
        self::assertIsArray($data['indicators']);
        self::assertCount(\count($data['diagrams']), $catalog->diagrams());
        self::assertCount(\count($data['indicators']), $catalog->indicators());
        self::assertCount(40, $catalog->diagrams());
        self::assertCount(70, $catalog->indicators());

        $thorax = $catalog->diagram('thoraxschmerz');
        self::assertInstanceOf(MtsCatalogEntry::class, $thorax);
        self::assertSame('Thoraxschmerz', $thorax->germanName);
        self::assertNull($catalog->diagram('unknown'));
    }

    public function testCodesAreSortedValidCodes(): void
    {
        $catalog = new MtsCatalog();
        foreach ([$catalog->diagrams(), $catalog->indicators()] as $entries) {
            $codes = array_keys($entries);
            $sorted = $codes;
            sort($sorted, \SORT_STRING);
            self::assertSame($sorted, $codes);
            foreach ($entries as $code => $entry) {
                self::assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9_]{0,62}[a-z0-9])?$/', $code);
                self::assertSame($code, $entry->code);
                self::assertSame($code, MtsCatalog::code($entry->germanName));
            }
        }
    }

    /**
     * Same examples as `catalogCode()` in tests/Encounter/DataPrep/data-prep-core.test.js and
     * docs/04-features/tools/data-preparation-tool.md.
     */
    #[DataProvider('codes')]
    public function testCodeIsDerivedLikeTheTool(string $label, string $expected): void
    {
        self::assertSame($expected, MtsCatalog::code($label));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function codes(): iterable
    {
        yield 'documented example' => ['Abdominelle Schmerzen bei Erwachsenen', 'abdominelle_schmerzen_bei_erwachsenen'];
        yield 'umlaut' => ['Stürze', 'stuerze'];
        yield 'leading umlaut' => ['Überdosierung und Vergiftung', 'ueberdosierung_und_vergiftung'];
        yield 'eszett' => ['Androhung von Gewalt oder hohes Risiko der Fremdgefährdung', 'androhung_von_gewalt_oder_hohes_risiko_der_fremdgefaehrdung'];
        yield 'parentheses' => ['Angriff (Zustand nach)', 'angriff_zustand_nach'];
        yield 'other diacritics and spaces' => ['  Café   Crème ', 'cafe_creme'];
        yield 'cut at 64 without trailing underscore' => [str_repeat('a', 63).' b', str_repeat('a', 63)];
    }

    /**
     * The canonical output of the tool for the synthetic sample uses catalog codes only.
     */
    public function testToolOutputForTheSampleUsesCatalogCodes(): void
    {
        $catalog = new MtsCatalog();
        $lines = file(self::FIXTURES.'/orbis-mts-sample.ed_visit.csv', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $header = str_getcsv($lines[1], ';', '"', '');
        $diagrams = [];
        foreach (\array_slice($lines, 2) as $line) {
            $row = array_combine($header, str_getcsv($line, ';', '"', ''));
            foreach (['mts_diagram', 'retriage_diagram'] as $column) {
                if ('' !== $row[$column]) {
                    $diagrams[] = $row[$column];
                }
            }
        }

        self::assertNotSame([], $diagrams);
        foreach ($diagrams as $code) {
            self::assertInstanceOf(MtsCatalogEntry::class, $catalog->diagram($code), $code);
        }
    }

    /**
     * English diagram names are English-only translations of catalog diagrams; German comes from the catalog.
     */
    public function testEnglishDiagramNamesBelongToCatalogDiagramsOnly(): void
    {
        $catalog = new MtsCatalog();
        $prefix = 'encounter.mts.diagram_name.';

        $english = $this->keysWithPrefix('en', $prefix);
        self::assertNotSame([], $english);
        foreach ($english as $code) {
            self::assertNotNull($catalog->diagram($code), $code);
        }

        self::assertSame([], $this->keysWithPrefix('de', $prefix));
        self::assertSame([], $this->keysWithPrefix('en', 'encounter.mts.indicator_name.'));
    }

    /**
     * @return list<string>
     */
    private function keysWithPrefix(string $locale, string $prefix): array
    {
        $xml = simplexml_load_file(self::TRANSLATIONS.'/encounter+intl-icu.'.$locale.'.xlf');
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $codes = [];
        foreach ($xml->xpath('//x:trans-unit/@resname') ?: [] as $resname) {
            $key = (string) $resname;
            if (str_starts_with($key, $prefix)) {
                $codes[] = substr($key, \strlen($prefix));
            }
        }

        return $codes;
    }
}
