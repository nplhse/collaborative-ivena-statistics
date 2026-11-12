<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Encounter\OverviewDataFixtures;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Hospital page "Encounter data" (#1055): beta role and VIEW_AGGREGATES on the hospital (any
 * encounter grant plus the hospital permission STATISTICS), administrators every hospital. Figures
 * come from OverviewDataFixtures (period 10–12 October 2026: 9 visits of hospital A).
 */
#[ResetDatabase]
final class EncounterOverviewPageTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;
    use OverviewDataFixtures;

    private KernelBrowser $client;

    private Hospital $hospitalA;

    private Hospital $hospitalB;

    private User $ownerA;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->ownerA = $this->user();
        $this->hospitalA = $this->hospital($this->ownerA);
        $this->hospitalB = $this->hospital($this->user());
        $this->loadOverviewData($this->hospitalA, $this->hospitalB, $this->user());
    }

    public function testOwnerWithAnyGrantSeesTheFiguresOfThePeriod(): void
    {
        $this->encounterGrant($this->hospitalA, $this->ownerA, [EncounterGrantAction::Upload]);
        $this->client->loginUser($this->ownerA);

        $crawler = $this->page($this->hospitalA, ['from' => '2026-10-10', 'to' => '2026-10-12']);

        self::assertSame('9', $this->text($crawler, '[data-testid="encounter-coverage-visits"]'));
        self::assertSame('3', $this->text($crawler, '[data-testid="encounter-coverage-days"]'));
        self::assertSame('2', $this->text($crawler, '[data-testid="encounter-coverage-imports"]'));
        self::assertStringContainsString('11 visits', $this->text($crawler, '[data-testid="encounter-coverage-all-time"]'));

        $total = '[data-testid="encounter-route-link-row"][data-route="total"]';
        self::assertSame('< 5', $this->text($crawler, $total.' [data-testid="encounter-count"][data-key="automatic"]'), '3 automatic links are suppressed.');
        self::assertSame('5', $this->text($crawler, $total.' [data-testid="encounter-count"][data-key="not_linked"]'));
        self::assertSame('9', $this->text($crawler, $total.' [data-testid="encounter-count"][data-key="total"]'));
        self::assertSame('–', $this->text($crawler, '[data-route="self"] [data-testid="encounter-count"][data-key="automatic"]'));

        $assessment = '[data-testid="encounter-process-row"][data-interval="admission_to_assessment"]';
        self::assertSame('17.5 min', $this->text($crawler, $assessment.' [data-testid="encounter-process-median"]'));
        self::assertSame('11.3 – 23.8 min', $this->text($crawler, $assessment.' [data-testid="encounter-process-iqr"]'));
        self::assertSame('6', $this->text($crawler, $assessment.' [data-testid="encounter-process-valid"]'));
        self::assertSame('< 5', $this->text($crawler, $assessment.' [data-testid="encounter-process-missing"]'));
        self::assertSame('180 min', $this->text($crawler, '[data-interval="admission_to_discharge"] [data-testid="encounter-process-median"]'));

        self::assertSame('< 5', $this->text($crawler, '[data-testid="encounter-categories-row"][data-key="orange"] [data-testid="encounter-count"]'));
        self::assertCount(5, $crawler->filter('[data-testid="encounter-diagrams-row"]'), 'Three diagrams, other, not documented.');
    }

    public function testPeriodWithoutVisitsShowsTheEmptyState(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->page($this->hospitalA, ['from' => '2026-11-01', 'to' => '2026-11-30']);

        self::assertSame('0', $this->text($crawler, '[data-testid="encounter-coverage-visits"]'));
        self::assertSelectorExists('[data-testid="encounter-overview-empty"]');
    }

    public function testAccessNeedsAGrantAndTheStatisticsPermissionOnThatHospital(): void
    {
        $uploader = $this->user();
        $this->hospitalGrant($this->hospitalA, $uploader);
        $this->encounterGrant($this->hospitalA, $uploader, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($uploader);
        $this->client->request(Request::METHOD_GET, $this->url($this->hospitalA));
        self::assertResponseStatusCodeSame(403, 'Hospital permission VIEW only, no STATISTICS.');

        $this->encounterGrant($this->hospitalA, $this->ownerA, [EncounterGrantAction::Review]);
        $this->client->loginUser($this->ownerA);
        $this->client->request(Request::METHOD_GET, $this->url($this->hospitalB));
        self::assertResponseStatusCodeSame(403, 'Another hospital is never shown.');

        $this->client->loginUser($this->user());
        $this->client->request(Request::METHOD_GET, $this->url($this->hospitalA));
        self::assertResponseStatusCodeSame(403, 'Beta role without grant.');

        $withoutBeta = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->encounterGrant($this->hospitalA, $withoutBeta, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($withoutBeta);
        $this->client->request(Request::METHOD_GET, $this->url($this->hospitalA));
        self::assertResponseStatusCodeSame(403);
    }

    public function testEntryPageListsTheHospitalsWithAggregates(): void
    {
        $this->encounterGrant($this->hospitalA, $this->ownerA, [EncounterGrantAction::Upload]);
        $this->client->loginUser($this->ownerA);
        $crawler = $this->client->request(Request::METHOD_GET, '/encounter', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $this->hospitalA->getId()], $crawler->filter('[data-testid="encounter-overview-hospital"]')->each(static fn (Crawler $link): string => (string) $link->attr('data-hospital')));

        $this->client->loginUser($this->user());
        $this->client->request(Request::METHOD_GET, '/encounter', server: ['HTTP_Accept-Language' => 'en']);
        self::assertSelectorExists('[data-testid="encounter-overview-hospitals-none"]');
    }

    public function testAdministratorSeesEveryHospitalButEachOnItsOwn(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->client->request(Request::METHOD_GET, '/encounter', server: ['HTTP_Accept-Language' => 'en']);
        self::assertCount(2, $crawler->filter('[data-testid="encounter-overview-hospital"]'));

        $crawler = $this->page($this->hospitalB, ['from' => '2026-10-10', 'to' => '2026-10-12']);
        self::assertSame('6', $this->text($crawler, '[data-testid="encounter-coverage-visits"]'));
        self::assertSame('6', $this->text($crawler, '[data-testid="encounter-categories-row"][data-key="red"] [data-testid="encounter-count"]'));
    }

    public function testGermanPageHasNoMissingTranslations(): void
    {
        $this->client->loginUser($this->admin());
        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, $this->url($this->hospitalA), server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        $this->assertNoMissingTranslations($this->client->getProfile());
    }

    /**
     * @param array<string, string> $query
     */
    private function page(Hospital $hospital, array $query = []): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, $this->url($hospital), $query, server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function url(Hospital $hospital): string
    {
        return '/encounter/hospital/'.$hospital->getId();
    }

    private function text(Crawler $crawler, string $selector): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $crawler->filter($selector)->text()));
    }
}
