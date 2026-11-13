<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Catalog;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use App\Encounter\Application\Contract\MtsCatalogReaderInterface;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The MTS catalog (ADR 032): codes are the ones the tool writes, and readers resolve a code in one step (active
 * entry → itself, alias → its active target, anything else → nothing). The database content is covered by
 * MtsCatalogSeedTest.
 */
final class MtsCatalogTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../DataPrep/fixtures';
    private const string TRANSLATIONS = __DIR__.'/../../../../../translations';

    public function testActiveEntriesResolveToThemselvesAndMergedOnesAreNotListed(): void
    {
        $catalog = $this->catalog();

        self::assertSame(['brustschmerz', 'thoraxschmerz', 'zz_neu'], array_keys($catalog->diagrams()));
        self::assertEquals(new MtsCatalogEntry('thoraxschmerz', 'Thoraxschmerz', 'Chest pain'), $catalog->diagram('thoraxschmerz'));
        self::assertEquals(new MtsCatalogEntry('zz_neu', 'zz_neu'), $catalog->diagram('zz_neu'), 'a provisional entry is active');
        self::assertSame(['heiss'], array_keys($catalog->indicators()));
    }

    public function testAliasesResolveInOneStepToTheirActiveTarget(): void
    {
        $catalog = $this->catalog();

        self::assertSame('thoraxschmerz', $catalog->diagram('thorax_schmerz_alt')?->code, 'merged entry → its alias target');
        self::assertSame('thoraxschmerz', $catalog->diagram('brustschmerzen')?->code, 'other alias → its target');
        self::assertSame('brustschmerz', $catalog->diagram('brustschmerz')?->code, 'an active entry wins over an alias with the same code');
        self::assertNull($catalog->diagram('kette'), 'an alias pointing to an alias resolves to nothing');
        self::assertNull($catalog->diagram('ins_leere'), 'an alias pointing to nothing resolves to nothing');
        self::assertNull($catalog->diagram('unbekannt'));
        self::assertNull($catalog->indicator('thoraxschmerz'), 'kinds are separate');
        self::assertSame('heiss', $catalog->indicator('heiss_alt')?->code);
    }

    public function testCodesResolvingToAnEntryListTheEntryAndItsAliases(): void
    {
        $catalog = $this->catalog();

        self::assertSame(['thoraxschmerz', 'brustschmerzen', 'thorax_schmerz_alt'], $catalog->codesResolvingTo(MtsCatalogKind::Diagram, 'thoraxschmerz'));
        self::assertSame(['zz_neu'], $catalog->codesResolvingTo(MtsCatalogKind::Diagram, 'zz_neu'));
    }

    public function testResetReadsTheCatalogAgain(): void
    {
        /** @var \ArrayObject<int, array{code: string, german_name: string, english_name: ?string, status: MtsCatalogStatus}> $added */
        $added = new \ArrayObject();
        $catalog = new MtsCatalog($this->reader($added));
        self::assertNull($catalog->diagram('spaeter'));

        $added->append(['code' => 'spaeter', 'german_name' => 'Später', 'english_name' => null, 'status' => MtsCatalogStatus::Curated]);
        self::assertNull($catalog->diagram('spaeter'), 'read once per request');
        $catalog->reset();
        self::assertSame('Später', $catalog->diagram('spaeter')?->germanName);
    }

    public function testBundledCopyOfTheToolStaysTheSeedFile(): void
    {
        $data = MtsCatalog::bundledData();

        self::assertSame(MtsCatalog::ID, $data['id']);
        self::assertIsArray($data['diagrams']);
        self::assertIsArray($data['indicators']);
        self::assertCount(40, $data['diagrams']);
        self::assertCount(70, $data['indicators']);
        self::assertSame($data, $this->catalog()->data());
    }

    /**
     * Same examples as `catalogCode()` in tests/Encounter/DataPrep/data-prep-core.test.js and
     * docs/04-features/encounter/data-preparation-tool.md.
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
     * The canonical output of the tool for the synthetic sample uses codes of the bundled catalog only.
     */
    public function testToolOutputForTheSampleUsesCatalogCodes(): void
    {
        $data = MtsCatalog::bundledData();
        self::assertIsArray($data['diagrams']);
        $codes = array_map(static fn (mixed $label): string => MtsCatalog::code((string) $label), $data['diagrams']);

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
            self::assertContains($code, $codes);
        }
    }

    /**
     * English names are catalog data since ADR 032, not translations.
     */
    public function testEnglishNamesAreNoLongerTranslations(): void
    {
        foreach (['en', 'de'] as $locale) {
            $content = file_get_contents(self::TRANSLATIONS.'/encounter+intl-icu.'.$locale.'.xlf');
            self::assertIsString($content);
            self::assertStringNotContainsString('encounter.mts.diagram_name.', $content);
            self::assertStringNotContainsString('encounter.mts.indicator_name.', $content);
        }
    }

    private function catalog(): MtsCatalog
    {
        return new MtsCatalog($this->reader());
    }

    /**
     * @param \ArrayObject<int, array{code: string, german_name: string, english_name: ?string, status: MtsCatalogStatus}>|null $added diagrams added later
     */
    private function reader(?\ArrayObject $added = null): MtsCatalogReaderInterface
    {
        return new readonly class($added ?? new \ArrayObject()) implements MtsCatalogReaderInterface {
            private const array DIAGRAMS = [
                ['code' => 'brustschmerz', 'german_name' => 'Brustschmerz', 'english_name' => null, 'status' => MtsCatalogStatus::Curated],
                ['code' => 'thorax_schmerz_alt', 'german_name' => 'Thorax-Schmerz alt', 'english_name' => null, 'status' => MtsCatalogStatus::Merged],
                ['code' => 'thoraxschmerz', 'german_name' => 'Thoraxschmerz', 'english_name' => 'Chest pain', 'status' => MtsCatalogStatus::Curated],
                ['code' => 'zz_neu', 'german_name' => 'zz_neu', 'english_name' => null, 'status' => MtsCatalogStatus::Provisional],
            ];

            /**
             * @param \ArrayObject<int, array{code: string, german_name: string, english_name: ?string, status: MtsCatalogStatus}> $added
             */
            public function __construct(private \ArrayObject $added)
            {
            }

            #[\Override]
            public function entries(MtsCatalogKind $kind): array
            {
                return MtsCatalogKind::Diagram === $kind
                    ? [...self::DIAGRAMS, ...$this->added->getArrayCopy()]
                    : [['code' => 'heiss', 'german_name' => 'Heiß', 'english_name' => null, 'status' => MtsCatalogStatus::Curated]];
            }

            #[\Override]
            public function aliases(MtsCatalogKind $kind): array
            {
                return MtsCatalogKind::Diagram === $kind
                    ? [
                        'brustschmerz' => 'thoraxschmerz',
                        'brustschmerzen' => 'thoraxschmerz',
                        'ins_leere' => 'gibt_es_nicht',
                        'kette' => 'brustschmerzen',
                        'thorax_schmerz_alt' => 'thoraxschmerz',
                    ]
                    : ['heiss_alt' => 'heiss'];
            }
        };
    }
}
