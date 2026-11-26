<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\UI\DataPrep;

use App\Encounter\UI\DataPrep\DataPrepChangelog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * The changelog of the data preparation tool (#1130) has an entry for the configured version, newest
 * first, and every note is translated in English and German.
 */
final class DataPrepChangelogTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../../..';

    public function testNewestEntryIsTheConfiguredVersion(): void
    {
        /** @var array{app: array{encounter: array{data_prep: array{version: string, released_at: string}}}} $config */
        $config = Yaml::parseFile(self::ROOT.'/config/packages/app.yaml');
        $release = $config['app']['encounter']['data_prep'];

        $entries = new DataPrepChangelog()->entries();

        self::assertSame($release['version'], $entries[0]['version'], 'Add a changelog entry when raising app.encounter.data_prep.version.');
        self::assertSame($release['released_at'], $entries[0]['released_at']);
    }

    public function testEntriesAreNewestFirstWithNotes(): void
    {
        $entries = new DataPrepChangelog()->entries();

        for ($i = 1, $count = \count($entries); $i < $count; ++$i) {
            self::assertTrue(version_compare($entries[$i - 1]['version'], $entries[$i]['version'], '>'), $entries[$i]['version']);
            self::assertGreaterThanOrEqual($entries[$i]['released_at'], $entries[$i - 1]['released_at']);
        }
        foreach ($entries as $entry) {
            self::assertNotEmpty($entry['notes'], $entry['version']);
        }
    }

    public function testNotesAreTranslatedInEnglishAndGerman(): void
    {
        $loader = new XliffFileLoader();
        foreach (['en', 'de'] as $locale) {
            $catalogue = $loader->load(self::ROOT.sprintf('/translations/encounter+intl-icu.%s.xlf', $locale), $locale, 'encounter');
            foreach (new DataPrepChangelog()->entries() as $entry) {
                foreach ($entry['notes'] as $note) {
                    self::assertTrue($catalogue->has($note, 'encounter'), sprintf('%s missing in %s', $note, $locale));
                }
            }
        }
    }
}
