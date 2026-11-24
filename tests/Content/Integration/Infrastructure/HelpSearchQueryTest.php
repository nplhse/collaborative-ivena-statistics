<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Infrastructure;

use App\Content\Application\Contract\HelpSearchQueryInterface;
use App\Content\Application\Help\HelpSearchHit;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Tests\Support\Content\HelpPages;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;

/**
 * Fixture: "export" is the title of one article and only text of another; one article has a German translation, one
 * only English, one a German draft.
 */
final class HelpSearchQueryTest extends DatabaseKernelTestCase
{
    private Page $export;
    private Page $rejects;
    private Page $limits;
    private Page $explorer;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $root = HelpPages::root();
        $this->export = HelpPages::article($root, 'Preparing the IVENA export', '/help/export', '<p>Choose one quarter at a time.</p>', 0, ['summary' => 'Create the CSV file in IVENA, not in a spreadsheet.']);
        $this->rejects = HelpPages::article($root, 'Understanding rejected rows', '/help/rejected-rows', '<p>Rows that fail the plausibility checks are rejected.</p>');
        HelpPages::german($this->rejects, 'Abgelehnte Zeilen verstehen', '/hilfe/abgelehnte-zeilen', '<p>Zeilen, die die Plausibilitätsprüfung nicht bestehen, werden abgelehnt.</p>');
        $this->limits = HelpPages::article($root, 'File size limits', '/help/limits', '<p>Large files take longer; split the export by quarter.</p>');
        $this->explorer = HelpPages::article($root, 'Analysis explorer', '/help/explorer', '<p>Filter the allocations.</p>');
        HelpPages::german($this->explorer, 'Analyse-Explorer', '/hilfe/explorer', '<p>Geheimer Entwurf.</p>', PageTranslation::STATUS_DRAFT);
    }

    public function testTitleMatchesRankAboveTextMatches(): void
    {
        self::assertSame(['Preparing the IVENA export', 'File size limits'], $this->titles($this->search('export', 'en')));
    }

    public function testWordsMatchAsPrefixAndAllWordsAreRequired(): void
    {
        self::assertSame(['Understanding rejected rows'], $this->titles($this->search('rej', 'en')));
        self::assertSame(['Understanding rejected rows'], $this->titles($this->search('rejected plausib', 'en')));
        self::assertSame([], $this->search('rejected quarter', 'en'));
    }

    public function testEnglishWordsAreStemmed(): void
    {
        self::assertSame(['Understanding rejected rows'], $this->titles($this->search('rejecting', 'en')));
    }

    public function testGermanVersionIsSearchedWithGermanStemsAndOthersFallBackToEnglish(): void
    {
        self::assertSame(['Abgelehnte Zeilen verstehen'], $this->titles($this->search('abgelehnten', 'de')), 'abgelehnten and abgelehnt share the stem');
        self::assertSame([], $this->search('rejected', 'de'), 'the German version is shown, so its English text is not searched');
        self::assertSame(['Preparing the IVENA export', 'File size limits'], $this->titles($this->search('export', 'de')), 'English fallback');
        self::assertSame(['Analysis explorer'], $this->titles($this->search('filter', 'de')), 'a German draft is not shown');
        self::assertSame([], $this->search('geheimer', 'de'));
    }

    public function testOnlyTheGivenArticlesAreSearched(): void
    {
        $hits = self::getContainer()->get(HelpSearchQueryInterface::class)->search('export', [(int) $this->limits->getId()], 'en', 'en');

        self::assertSame(['File size limits'], $this->titles($hits));
        self::assertSame([], self::getContainer()->get(HelpSearchQueryInterface::class)->search('export', [], 'en', 'en'));
    }

    public function testQuerySyntaxIsIgnoredAndTheExcerptMarksMatches(): void
    {
        self::assertSame([], $this->search('& | ! :* ()', 'en'));

        $hits = $this->search("rows' & !", 'en');
        self::assertSame(['Understanding rejected rows'], $this->titles($hits));
        self::assertSame('/help/rejected-rows', $hits[0]->path);
        self::assertStringContainsString('<mark>Rows</mark>', $hits[0]->excerptHtml());
    }

    public function testTheSummaryIsSearchedAndReturned(): void
    {
        $hits = $this->search('spreadsheet', 'en');

        self::assertSame(['Preparing the IVENA export'], $this->titles($hits), 'the word only occurs in the summary');
        self::assertSame('Create the CSV file in IVENA, not in a spreadsheet.', $hits[0]->summary);
        self::assertNull($this->search('plausibility', 'en')[0]->summary);
    }

    /**
     * @return list<HelpSearchHit>
     */
    private function search(string $terms, string $locale): array
    {
        $ids = array_map(static fn (Page $page): int => (int) $page->getId(), [$this->export, $this->rejects, $this->limits, $this->explorer]);

        return self::getContainer()->get(HelpSearchQueryInterface::class)->search($terms, $ids, $locale, 'en');
    }

    /**
     * @param list<HelpSearchHit> $hits
     *
     * @return list<string>
     */
    private function titles(array $hits): array
    {
        return array_map(static fn (HelpSearchHit $hit): string => $hit->title, $hits);
    }
}
