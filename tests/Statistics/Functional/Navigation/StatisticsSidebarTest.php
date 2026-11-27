<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Navigation;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The statistics sidebar (ADR 035, step 5): entries per role, active path, and which query parameters the
 * links carry.
 */
#[ResetDatabase]
final class StatisticsSidebarTest extends WebTestCase
{
    use Factories;

    public function testAUserWithoutBetaRolesSeesNoBetaEntries(): void
    {
        $crawler = $this->get($this->client(['ROLE_USER']), '/statistics/top-lists');

        self::assertSelectorExists('[data-testid="sidebar-statistics"]');
        self::assertSelectorExists('[data-testid="sidebar-entry-top-lists"].active');
        self::assertSelectorNotExists('[data-testid="sidebar-group-closures"]');
        self::assertSelectorNotExists('[data-testid="sidebar-entry-encounter-analytics"]');
        self::assertSame(
            ['Allocations', 'Comparisons', 'Capacity & closures', 'Own analyses'],
            $crawler->filter('[data-testid="sidebar-statistics"] .nav-section-title:not(.site-sidebar__divider)')->each(static fn (Crawler $node): string => trim($node->text())),
            'The clinical group has no visible entry without the encounter beta role and is dropped.',
        );
    }

    public function testBetaEntriesCarryTheirBadge(): void
    {
        $this->get($this->client(['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA']), '/statistics/top-lists');

        self::assertSelectorNotExists('[data-testid="sidebar-group-closures"] .site-sidebar__badge', 'Closure Analytics left the beta (#1350).');
        self::assertSelectorTextContains('[data-testid="sidebar-entry-encounter-analytics"] .site-sidebar__badge.bg-azure-lt', 'Beta');
    }

    public function testClosuresAreShownOnlyWithBothRolesThePagesRequire(): void
    {
        $this->get($this->client(['ROLE_USER']), '/statistics/top-lists');

        self::assertSelectorNotExists(
            '[data-testid="sidebar-group-closures"]',
            'Closure Analytics also needs ROLE_PARTICIPANT; without it the pages answer 403, so the entry stays hidden.',
        );
    }

    public function testADetailPageOpensTheGroupsOnItsPath(): void
    {
        $this->get($this->client(['ROLE_USER']), '/statistics/insights/occasions');

        self::assertSelectorExists('[data-testid="sidebar-group-insights"].site-sidebar__on-path #site-sidebar-group-insights.show');
        self::assertSelectorExists('#site-sidebar-group-insights-dimensions.show');
        self::assertSelectorExists('[data-testid="sidebar-entry-insights-dimension-occasions"].active');
        self::assertSelectorNotExists('#site-sidebar-group-closures.show');
    }

    /**
     * The search (#1248) filters in the browser on what the page renders: the translated label of every
     * entry and group, its section, and the section titles. Badges are not part of the label.
     */
    public function testTheTreeCarriesWhatTheSearchFiltersOn(): void
    {
        $crawler = $this->get($this->client(['ROLE_USER', 'ROLE_PARTICIPANT']), '/statistics/top-lists');

        self::assertSelectorExists('[data-testid="sidebar-statistics"] input[type="search"][data-testid="sidebar-search"][aria-keyshortcuts="/"]');
        self::assertSelectorExists('[data-testid="sidebar-search-empty"][hidden]');

        $specialities = $crawler->filter('[data-testid="sidebar-entry-insights-dimension-specialities"]');
        self::assertSame('insights-dimension-specialities', $specialities->attr('data-nav-id'));
        self::assertSame($specialities->filter('.nav-link-title')->text(), $specialities->attr('data-nav-label'));
        self::assertSame(
            $crawler->filter('[data-testid="sidebar-group-insights"]')->attr('data-nav-section'),
            $specialities->attr('data-nav-section'),
            'Nested entries carry the section of their top-level group.',
        );

        $closures = $crawler->filter('[data-testid="sidebar-group-closures"]');
        self::assertStringNotContainsString('Beta', (string) $closures->attr('data-nav-label'));
        self::assertSame(
            'Capacity & closures',
            $crawler->filter(\sprintf('[data-nav-section-title="%s"]', $closures->attr('data-nav-section')))->attr('data-nav-label'),
        );
    }

    /**
     * The scope with its hospital is covered by EncounterAnalyticsControllerTest.
     */
    public function testLinksCarryScopeAndPeriodWithTheirParametersButNoOtherFilter(): void
    {
        $crawler = $this->get($this->client(['ROLE_USER']), '/statistics/top-lists', [
            'scope' => 'public',
            'period' => 'month',
            'year' => '2025',
            'month' => '4',
            'comparison_scope' => 'public',
            'comparison_period' => 'all',
            'age_group' => '0_17',
        ]);

        self::assertSame(
            '/statistics/benchmarking?scope=public&period=month&year=2025&month=4',
            $crawler->filter('[data-testid="sidebar-entry-benchmarking"] a')->attr('href'),
        );
        self::assertSame(
            '/statistics/insights/occasions?scope=public&period=month&year=2025&month=4',
            $crawler->filter('[data-testid="sidebar-entry-insights-dimension-occasions"] a')->attr('href'),
        );
    }

    public function testPartsOfOneAnalysisKeepTheWholeFilterStateExceptComparisonAndPage(): void
    {
        $crawler = $this->get($this->client(['ROLE_USER', 'ROLE_PARTICIPANT']), '/statistics/closure-analytics/duration', [
            'scope' => 'public',
            'period' => 'last_12_months',
            'closure_reasons' => ['capacity'],
            'comparison_period' => 'all',
            'page' => '3',
        ]);

        self::assertSelectorExists('[data-testid="sidebar-entry-closures-duration"].active');
        self::assertSame(
            '/statistics/closure-analytics/events?scope=public&period=last_12_months&closure_reasons%5B0%5D=capacity',
            $crawler->filter('[data-testid="sidebar-entry-closures-events"] a')->attr('href'),
            'Within Closures the shared filter stays; comparison parameters and the page number do not.',
        );
        self::assertSame(
            '/statistics/benchmarking?scope=public&period=last_12_months',
            $crawler->filter('[data-testid="sidebar-entry-benchmarking"] a')->attr('href'),
            'Leaving the analysis keeps only scope and period.',
        );
    }

    /**
     * @param list<string> $roles
     */
    private function client(array $roles): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['username' => 'sidebar-'.bin2hex(random_bytes(4)), 'roles' => $roles]));

        return $client;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(KernelBrowser $client, string $uri, array $query = []): Crawler
    {
        $crawler = $client->request(Request::METHOD_GET, $uri, $query, server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
