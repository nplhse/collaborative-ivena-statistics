<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
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
 * Encounter analytics in Statistics (#1055): the statistics scope and period picker with exactly one
 * hospital (VIEW_AGGREGATES; administrators every hospital with visits). Data: OverviewDataFixtures.
 *
 * October 2026 (`period=month&year=2026&month=10`) holds all 11 visits of hospital A: V1–V7, B1–B4.
 * B1 and B4 are EMS arrivals without link and without ED admission, so compared with the 10–12 October
 * figures of EncounterOverviewQueryTest: EMS 7 (automatic 3, confirmed 1, not linked 3), not linked 7
 * of 11, and 5 visits instead of 3 miss the admission time; medians stay 17.5 / 45 / 180 minutes.
 */
#[ResetDatabase]
final class EncounterAnalyticsControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;
    use OverviewDataFixtures;

    private const array OCTOBER = ['period' => 'month', 'year' => '2026', 'month' => '10'];

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

    public function testOwnerWithAnyGrantSeesTheFiguresOfTheMonth(): void
    {
        $this->encounterGrant($this->hospitalA, $this->ownerA, [EncounterGrantAction::Upload]);
        $this->client->loginUser($this->ownerA);

        $crawler = $this->page($this->hospitalA, self::OCTOBER);
        self::assertSame('11', $this->text($crawler, '[data-kpi="visits"] [data-testid="encounter-kpi-value"]'));
        self::assertSame('7', $this->text($crawler, '[data-testid="encounter-routes-segment"][data-key="ems"] [data-testid="encounter-count"]'));
        self::assertSame('< 5', $this->text($crawler, '[data-testid="encounter-routes-segment"][data-key="self"] [data-testid="encounter-count"]'));
        self::assertSame('45 min', $this->text($crawler, '[data-kpi="physician"] [data-testid="encounter-kpi-value"]'));
        self::assertSame('180 min', $this->text($crawler, '[data-kpi="length_of_stay"] [data-testid="encounter-kpi-value"]'));
        self::assertSame('< 5', $this->text($crawler, '[data-kpi="admitted"] [data-testid="encounter-kpi-value"]'), 'V1, V2, V4 admitted: suppressed.');
        self::assertCount(4, $crawler->filter('[data-testid="encounter-kpi"]'));
        self::assertSame('< 5', $this->text($crawler, '[data-testid="encounter-categories-bar-segment"][data-key="red"] [data-testid="encounter-count"]'));
        self::assertSame('Visits per day', $this->text($crawler, '[data-testid="encounter-time-series"] .card-title'), 'A month period is drawn per day, as in the statistics overview.');
        self::assertCount(3, $crawler->filter('[data-testid="encounter-top-diagrams-row"]'));
        self::assertStringContainsString($this->hospitalName($this->hospitalA), $this->text($crawler, '[data-testid="stats-analysis-context-location"]'));

        $crawler = $this->page($this->hospitalA, self::OCTOBER + ['tab' => 'link']);
        $total = '[data-testid="encounter-route-link-row"][data-route="total"]';
        self::assertSame('< 5', $this->text($crawler, $total.' [data-testid="encounter-count"][data-key="automatic"]'));
        self::assertSame('7', $this->text($crawler, $total.' [data-testid="encounter-count"][data-key="not_linked"]'));
        self::assertSame('11', $this->text($crawler, $total.' [data-testid="encounter-count"][data-key="total"]'));

        $crawler = $this->page($this->hospitalA, self::OCTOBER + ['tab' => 'process']);
        $assessment = '[data-testid="encounter-process-row"][data-interval="admission_to_assessment"]';
        self::assertSame('17.5 min', $this->text($crawler, $assessment.' [data-testid="encounter-process-median"]'));
        self::assertSame('11.3 – 23.8 min', $this->text($crawler, $assessment.' [data-testid="encounter-process-iqr"]'));
        self::assertSame('6', $this->text($crawler, $assessment.' [data-testid="encounter-process-valid"]'));
        self::assertSame('5', $this->text($crawler, $assessment.' [data-testid="encounter-process-missing"]'), 'V6, B1–B4 without ED admission.');
        self::assertCount(3, $crawler->filter('[data-testid="encounter-process-box"]'));
        self::assertSelectorNotExists('[data-testid="encounter-process-box"] [data-controller="encounter-overview-chart"]', 'Every MTS category has fewer than 5 durations.');

        $crawler = $this->page($this->hospitalA, self::OCTOBER + ['tab' => 'distributions']);
        self::assertSame('< 5', $this->text($crawler, '[data-testid="encounter-categories-row"][data-key="red"] [data-testid="encounter-count"]'), 'V1, B1, B4.');
        self::assertCount(5, $crawler->filter('[data-testid="encounter-diagrams-row"]'), 'Three diagrams, other, not documented.');

        $crawler = $this->page($this->hospitalA, self::OCTOBER);
        self::assertSelectorNotExists('[data-testid="encounter-info"]');
        self::assertSelectorNotExists('[data-testid="encounter-overview-method"]');
        self::assertSelectorNotExists('[data-testid="encounter-overview-tab-coverage"]');
        self::assertSame('11', $this->text($crawler, '[data-testid="encounter-coverage-all"] [data-testid="encounter-coverage-visits"]'));
        self::assertSame('11', $this->text($crawler, '[data-testid="encounter-coverage-period"] [data-testid="encounter-coverage-visits"]'));
        self::assertSame('5', $this->text($crawler, '[data-testid="encounter-coverage-period"] [data-testid="encounter-coverage-days"]'));
        self::assertSame('2', $this->text($crawler, '[data-testid="encounter-coverage-period"] [data-testid="encounter-coverage-imports"]'));
    }

    public function testMonthWithoutVisitsShowsTheEmptyState(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->page($this->hospitalA, ['period' => 'month', 'year' => '2026', 'month' => '9']);
        self::assertSelectorExists('[data-testid="encounter-overview-empty"]');
        self::assertSame('0', $this->text($crawler, '[data-testid="encounter-coverage-period"] [data-testid="encounter-coverage-visits"]'));
        self::assertSame('09.10.2026 – 13.10.2026', $this->text($crawler, '[data-testid="encounter-coverage-all"] [data-testid="encounter-coverage-range"]'), 'Shows where the data lie.');
    }

    public function testScopeIsAlwaysExactlyOneAllowedHospital(): void
    {
        $this->encounterGrant($this->hospitalA, $this->ownerA, [EncounterGrantAction::Upload]);
        $this->client->loginUser($this->ownerA);

        $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics', self::OCTOBER);
        self::assertResponseRedirects('/statistics/encounter-analytics?period=month&year=2026&month=10&scope=hospital&hospital='.$this->hospitalA->getId());

        $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics', ['scope' => 'hospital', 'hospital' => (string) $this->hospitalB->getId()]);
        self::assertResponseRedirects('/statistics/encounter-analytics?scope=hospital&hospital='.$this->hospitalA->getId(), null, 'Another hospital is never shown.');

        $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics', ['scope' => 'public']);
        self::assertResponseRedirects('/statistics/encounter-analytics?scope=hospital&hospital='.$this->hospitalA->getId(), null, 'No public or aggregate scope.');

        $crawler = $this->page($this->hospitalA);
        self::assertSame(['hospital:'.$this->hospitalA->getId()], $crawler->filter('[data-testid="stats-analysis-context-hospitals"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function testUsersWithoutAggregatesGetNoFigures(): void
    {
        $viewOnly = $this->user();
        $this->hospitalGrant($this->hospitalA, $viewOnly);
        $this->encounterGrant($this->hospitalA, $viewOnly, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($viewOnly);
        $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="encounter-overview-no-access"]', 'Hospital permission VIEW only, no STATISTICS.');
        self::assertSelectorNotExists('[data-kpi="visits"]');

        $this->client->loginUser($this->user());
        $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics');
        self::assertSelectorExists('[data-testid="encounter-overview-no-access"]', 'Beta role without grant.');

        $withoutBeta = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->encounterGrant($this->hospitalA, $withoutBeta, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($withoutBeta);
        $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdministratorPicksAmongAllHospitalsOneAtATime(): void
    {
        $this->client->loginUser($this->admin());

        $crawler = $this->page($this->hospitalB, self::OCTOBER);
        self::assertCount(2, $crawler->filter('[data-testid="stats-analysis-context-hospitals"] option'));
        self::assertSame('6', $this->text($crawler, '[data-kpi="visits"] [data-testid="encounter-kpi-value"]'));
        self::assertSame('6', $this->text($crawler, '[data-testid="encounter-categories-bar-segment"][data-key="red"] [data-testid="encounter-count"]'));
    }

    public function testMyHospitalsAndSubnavLinkThePage(): void
    {
        $this->encounterGrant($this->hospitalA, $this->ownerA, [EncounterGrantAction::Upload]);
        $this->client->loginUser($this->ownerA);

        $crawler = $this->client->request(Request::METHOD_GET, '/hospitals', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        $action = $crawler->filter(\sprintf('[data-testid="my-hospitals-card"][data-hospital-id="%d"] [data-action-key="encounter_data"]', $this->hospitalA->getId()));
        self::assertCount(1, $action, 'The card of the hospital with an encounter grant offers the analysis (#1128).');
        self::assertSame('/statistics/encounter-analytics?scope=hospital&hospital='.$this->hospitalA->getId(), $action->attr('href'));
        self::assertStringContainsString('Encounter data', $action->text());

        $crawler = $this->page($this->hospitalA);
        self::assertSame('/statistics/encounter-analytics', $crawler->filter('[data-testid="stats-subnav-encounter-analytics"]')->attr('href'));
    }

    public function testMyHospitalsOffersTheAnalysisOnlyOnListedHospitals(): void
    {
        // Administrators may open the analysis of every hospital with visits (A and B), but My Hospitals lists
        // only the hospitals they hold a permission for (A): B gets no card, so no button.
        $admin = $this->admin();
        $this->hospitalGrant($this->hospitalA, $admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/hospitals', server: ['HTTP_Accept-Language' => 'en']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/statistics/encounter-analytics?scope=hospital&hospital='.$this->hospitalA->getId()],
            $crawler->filter('[data-action-key="encounter_data"]')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
    }

    public function testReviewersGetLinksToReviewAndValidationWithThePeriod(): void
    {
        $this->encounterGrant($this->hospitalA, $this->ownerA, [EncounterGrantAction::Upload]);
        $this->client->loginUser($this->ownerA);

        $this->page($this->hospitalA, self::OCTOBER);
        self::assertSelectorExists('[data-kpi="visits"]');
        self::assertSelectorNotExists('[data-testid="encounter-overview-review-links"]', 'Upload grant alone: no review.');

        $reviewer = $this->user();
        HospitalAccessGrantFactory::createOne([
            'hospital' => $this->hospitalA,
            'user' => $reviewer,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View, HospitalPermission::Statistics]),
            'createdBy' => $this->ownerA,
        ]);
        $this->encounterGrant($this->hospitalA, $reviewer, [EncounterGrantAction::Review]);
        $this->client->loginUser($reviewer);

        $id = (string) $this->hospitalA->getId();
        $crawler = $this->page($this->hospitalA, self::OCTOBER);
        self::assertSame('/tools/encounter-review/'.$id.'/overview?from=2026-10-01&to=2026-10-31', $crawler->filter('[data-testid="encounter-overview-review-link"]')->attr('href'));
        self::assertSame('/tools/encounter-review/'.$id.'/validation?from=2026-10-01&to=2026-10-31', $crawler->filter('[data-testid="encounter-overview-validation-link"]')->attr('href'));

        $crawler = $this->page($this->hospitalA, ['period' => 'year', 'year' => '2026']);
        self::assertSame('/tools/encounter-review/'.$id.'/overview', $crawler->filter('[data-testid="encounter-overview-review-link"]')->attr('href'), 'A year does not fit into one review page (at most 92 days).');

        $crawler = $this->page($this->hospitalA, ['period' => 'all_time']);
        self::assertSame('/tools/encounter-review/'.$id.'/validation', $crawler->filter('[data-testid="encounter-overview-validation-link"]')->attr('href'), 'An open-ended period has no end day to pass on.');

        $this->client->loginUser($this->admin());
        $this->page($this->hospitalB, self::OCTOBER);
        self::assertSelectorExists('[data-testid="encounter-overview-validation-link"]', 'Administrators review every hospital.');
    }

    public function testGermanPageHasNoMissingTranslations(): void
    {
        $this->client->loginUser($this->admin());
        foreach (['overview', 'process', 'link', 'distributions'] as $tab) {
            $this->client->enableProfiler();
            $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics', ['scope' => 'hospital', 'hospital' => (string) $this->hospitalA->getId(), 'tab' => $tab] + self::OCTOBER, server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

            self::assertResponseIsSuccessful();
            $this->assertNoMissingTranslations($this->client->getProfile());
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function page(Hospital $hospital, array $query = []): Crawler
    {
        $crawler = $this->client->request(
            Request::METHOD_GET,
            '/statistics/encounter-analytics',
            ['scope' => 'hospital', 'hospital' => (string) $hospital->getId()] + $query,
            server: ['HTTP_Accept-Language' => 'en'],
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function hospitalName(Hospital $hospital): string
    {
        return (string) $this->entityManager()->find(Hospital::class, $hospital->getId())?->getName();
    }

    private function text(Crawler $crawler, string $selector): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $crawler->filter($selector)->text()));
    }
}
