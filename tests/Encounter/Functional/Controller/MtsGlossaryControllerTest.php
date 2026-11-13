<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\Triage;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\MtsCategory;
use App\Encounter\Domain\Enum\Sex;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * MTS glossary in Explore (#1056): definitions for every participant, counts of the initial triage
 * only for users with encounter access (VIEW_CASES or VIEW_AGGREGATES, ADR 028) and only for their
 * hospitals, with small-cell suppression below 5 (ADR 012).
 *
 * Hospital A: 5 × orange/thoraxschmerz/heiss, 2 × yellow/thoraxschmerz/erbrechen,
 * 1 × yellow/thoraxschmerz without indicator, 1 × green/wunden/an uncatalogued indicator.
 * Hospital B: 7 × red/thoraxschmerz/grobe_fehlstellung, never visible to users of hospital A.
 */
#[ResetDatabase]
final class MtsGlossaryControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;

    private KernelBrowser $client;

    private Hospital $hospitalA;

    private Hospital $hospitalB;

    private int $visit = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->hospitalA = $this->hospital($this->user());
        $this->hospitalB = $this->hospital($this->user());

        $this->visits($this->hospitalA, 5, MtsCategory::Orange, 'thoraxschmerz', 'heiss');
        $this->visits($this->hospitalA, 2, MtsCategory::Yellow, 'thoraxschmerz', 'erbrechen');
        $this->visits($this->hospitalA, 1, MtsCategory::Yellow, 'thoraxschmerz', null);
        $this->visits($this->hospitalA, 1, MtsCategory::Green, 'wunden', 'neu_im_export');
        $this->visits($this->hospitalB, 7, MtsCategory::Red, 'thoraxschmerz', 'grobe_fehlstellung');
    }

    public function testGlossaryHubLinksTheMtsGlossary(): void
    {
        $this->client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/glossary');

        self::assertResponseIsSuccessful();
        self::assertSame('/explore/glossary/mts', $crawler->filter('[data-glossary-key="mts"]')->attr('href'));
    }

    public function testParticipantWithoutEncounterAccessSeesDefinitionsWithoutCounts(): void
    {
        $this->client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $crawler = $this->overview();

        self::assertCount(5, $crawler->filter('[data-testid="mts-category"]'));
        self::assertCount(40, $crawler->filter('[data-testid="mts-diagram"]'));
        self::assertCount(70, $crawler->filter('[data-testid="mts-indicator"]'));
        self::assertSelectorTextContains('[data-testid="mts-category"][data-code="orange"]', '10 minutes');
        self::assertSelectorTextContains('[data-testid="mts-category"][data-code="blue"]', '120 minutes');
        self::assertSelectorExists('[data-testid="mts-counts-none"]');
        self::assertSelectorNotExists('[data-testid="mts-count"]');

        $diagram = $this->diagram('thoraxschmerz');
        self::assertSelectorExists('[data-testid="mts-diagram-indicators-unavailable"]');
        self::assertCount(0, $diagram->filter('[data-testid="mts-indicator"]'));
        self::assertSelectorNotExists('[data-testid="mts-diagram-total"]');
    }

    public function testBetaUserWithoutGrantSeesNoCounts(): void
    {
        $user = $this->user();
        $this->hospitalGrant($this->hospitalA, $user);
        $this->client->loginUser($user);

        $this->overview();
        self::assertSelectorExists('[data-testid="mts-counts-none"]');
        self::assertSelectorNotExists('[data-testid="mts-count"]');
    }

    public function testCaseViewerSeesCountsOfTheOwnHospitalOnly(): void
    {
        $viewer = $this->user();
        $this->hospitalGrant($this->hospitalA, $viewer);
        $this->encounterGrant($this->hospitalA, $viewer, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($viewer);

        $crawler = $this->overview();
        self::assertSelectorTextContains('[data-testid="mts-counts-scope"]', 'the hospital');
        self::assertSame('5', $this->shownCount($crawler, 'mts-category', 'orange'));
        self::assertSame('< 5', $this->shownCount($crawler, 'mts-category', 'yellow'), '3 yellow visits are suppressed.');
        self::assertSame('< 5', $this->shownCount($crawler, 'mts-category', 'green'));
        self::assertSame('–', $this->shownCount($crawler, 'mts-category', 'red'), 'The 7 red visits of hospital B are not counted.');
        self::assertSame('8', $this->shownCount($crawler, 'mts-diagram', 'thoraxschmerz'));
        self::assertSame('< 5', $this->shownCount($crawler, 'mts-diagram', 'wunden'));
        self::assertSame('5', $this->shownCount($crawler, 'mts-indicator', 'heiss'));
        self::assertSame('–', $this->shownCount($crawler, 'mts-indicator', 'grobe_fehlstellung'));

        $diagram = $this->diagram('thoraxschmerz');
        self::assertSame('8', trim($diagram->filter('[data-testid="mts-diagram-total"]')->text()));
        self::assertSame(['erbrechen', 'heiss', ''], $this->indicatorCodes($diagram));
        self::assertSame('< 5', $this->shownCount($diagram, 'mts-indicator', 'erbrechen'));
        self::assertSame('5', $this->shownCount($diagram, 'mts-indicator', 'heiss'));
        self::assertSame('< 5', $this->shownCount($diagram, 'mts-indicator', ''), 'One visit without indicator.');
    }

    public function testDiagramPageFiltersIndicatorsByNameOrCode(): void
    {
        $viewer = $this->user();
        $this->encounterGrant($this->hospitalA, $viewer, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($viewer);

        self::assertSame(['heiss'], $this->indicatorCodes($this->diagram('thoraxschmerz', 'Heiß')));
        self::assertSame(['erbrechen'], $this->indicatorCodes($this->diagram('thoraxschmerz', 'erbre')));

        $this->diagram('thoraxschmerz', 'nothing');
        self::assertSelectorExists('[data-testid="mts-diagram-indicators-no-match"]');

        $wounds = $this->diagram('wunden');
        self::assertSame(['neu_im_export'], $this->indicatorCodes($wounds));
        self::assertSelectorTextContains('[data-testid="mts-indicator"][data-code="neu_im_export"]', 'not in the catalog');

        $this->diagram('kopfschmerz');
        self::assertSelectorExists('[data-testid="mts-diagram-indicators-empty"]');
    }

    public function testOwnerWithAnyGrantSeesAggregatesButUploaderWithoutStatisticsDoesNot(): void
    {
        $owner = $this->hospitalA->getOwner();
        self::assertInstanceOf(User::class, $owner);
        $this->encounterGrant($this->hospitalA, $owner, [EncounterGrantAction::Upload]);
        $this->client->loginUser($owner);
        self::assertSame('8', $this->shownCount($this->overview(), 'mts-diagram', 'thoraxschmerz'));

        $uploader = $this->user();
        $this->hospitalGrant($this->hospitalA, $uploader);
        $this->encounterGrant($this->hospitalA, $uploader, [EncounterGrantAction::Upload]);
        $this->client->loginUser($uploader);
        $this->overview();
        self::assertSelectorExists('[data-testid="mts-counts-none"]', 'UPLOAD with only the VIEW hospital permission gives no aggregates.');
    }

    public function testAdministratorSeesCountsOfAllHospitals(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->overview();
        self::assertSelectorExists('[data-testid="mts-counts-scope"]');
        self::assertSame('7', $this->shownCount($crawler, 'mts-category', 'red'));
        self::assertSame('15', $this->shownCount($crawler, 'mts-diagram', 'thoraxschmerz'));
    }

    public function testEnglishShowsWellKnownDiagramNamesAndGermanIndicatorNames(): void
    {
        $this->client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $this->overview();

        self::assertSelectorTextContains('[data-testid="mts-diagram"][data-code="thoraxschmerz"]', 'Chest pain');
        self::assertSelectorTextContains('[data-testid="mts-diagram"][data-code="thoraxschmerz"]', 'Thoraxschmerz');
        self::assertSelectorTextContains('[data-testid="mts-diagram"][data-code="generelle_indikatoren"]', 'Generelle Indikatoren');
        self::assertSelectorTextContains('[data-testid="mts-indicator"][data-code="heiss"]', 'Heiß');
        self::assertSelectorTextContains('[data-testid="mts-category"][data-code="red"]', 'Immediate');
    }

    public function testGermanPagesHaveNoMissingTranslations(): void
    {
        $viewer = $this->user();
        $this->encounterGrant($this->hospitalA, $viewer, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($viewer);
        $this->client->enableProfiler();
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/glossary/mts', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($this->client->getProfile());
        self::assertSame('Thoraxschmerz', trim($crawler->filter('[data-testid="mts-diagram"][data-code="thoraxschmerz"] a')->text()));
        self::assertSelectorTextContains('[data-testid="mts-category"][data-code="red"]', 'Sofort');

        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, '/explore/glossary/mts/diagram/thoraxschmerz', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);
        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($this->client->getProfile());
    }

    public function testUnknownDiagramIsNotFound(): void
    {
        $this->client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $this->client->request(Request::METHOD_GET, '/explore/glossary/mts/diagram/no_such_diagram');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGlossaryNeedsTheParticipantRole(): void
    {
        $this->client->loginUser($this->user(['ROLE_USER']));
        $this->client->request(Request::METHOD_GET, '/explore/glossary/mts');

        self::assertResponseStatusCodeSame(403);
    }

    private function overview(): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/glossary/mts', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function diagram(string $code, ?string $filter = null): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/glossary/mts/diagram/'.$code, null === $filter ? [] : ['q' => $filter], server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function shownCount(Crawler $crawler, string $row, string $code): string
    {
        return trim($crawler->filter(sprintf('[data-testid="%s"][data-code="%s"] [data-testid="mts-count"]', $row, $code))->text());
    }

    /**
     * @return list<string>
     */
    private function indicatorCodes(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="mts-diagram-indicators"] [data-testid="mts-indicator"]')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-code'),
        );
    }

    private function visits(Hospital $hospital, int $count, MtsCategory $category, string $diagram, ?string $indicator): void
    {
        $import = $this->encounterImport($hospital, 'MTS glossary '.$this->visit);
        for ($i = 0; $i < $count; ++$i) {
            ++$this->visit;
            $this->encounterRepository()->insertIfNew(new Encounter(
                hospital: $hospital,
                import: $import,
                keyGeneration: 1,
                visitFingerprint: hash('sha256', 'visit|'.$this->visit),
                caseKey: hash('sha256', 'case|'.$this->visit),
                age: 54,
                sex: Sex::Female,
                times: new EncounterTimes(firstAssessmentContactAt: new \DateTimeImmutable('2026-10-01 08:15')->modify('+'.$this->visit.' hours')),
                triage: new Triage($category, $diagram, $indicator),
            ));
        }
    }
}
