<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The navigation tree in the admin backend (#1237): the real providers of all contexts.
 */
#[ResetDatabase]
final class NavigationInspectorControllerTest extends WebTestCase
{
    use Factories;

    private const string URL = '/admin/operations/navigation';

    public function testAdminsSeeEveryMenuWithoutWarningsAndTheSystemMenuLinksToIt(): void
    {
        $crawler = $this->adminClient()->request(Request::METHOD_GET, self::URL);

        self::assertResponseIsSuccessful();
        foreach (['primary', 'footer', 'statistics', 'explore'] as $menu) {
            self::assertSelectorExists('[data-testid="nav-inspector-menu-'.$menu.'"]');
        }
        self::assertSelectorTextContains('[data-testid="nav-inspector-warnings"]', 'No problems found in any menu.');
        self::assertSelectorExists('[data-testid="nav-inspector-menu-statistics"] [data-nav-inspector-item="insights-dimension-occasions"]');
        self::assertSelectorExists('[data-nav-inspector-unclaimed="statistics"]');
        self::assertGreaterThan(0, $crawler->filter('#main-menu a[href*="/admin/operations/navigation"]')->count(), 'The System menu links to the page.');

        // Labels inside EasyAdmin components do not inherit trans_default_domain; they must still be translated.
        self::assertSame(['Check', 'Reset'], $crawler->filter('[data-testid="nav-inspector-form"] .btn')->each(static fn ($button): string => trim($button->text())));
        self::assertSame(['All', 'Only deviations'], $crawler->filter('[data-testid="nav-inspector-menu-primary"] [data-navigation-inspector-target="scope"]')->each(static fn ($button): string => trim($button->text())));
    }

    public function testHiddenEntriesAreShownWithTheirReason(): void
    {
        $client = $this->adminClient();
        UserFactory::new()->with(['username' => 'plain-viewer', 'roles' => ['ROLE_USER']])->create();

        $client->request(Request::METHOD_GET, self::URL, ['user' => 'plain-viewer']);

        self::assertSelectorExists(
            '[data-testid="nav-inspector-menu-statistics"] [data-nav-inspector-item="closures"] [data-visibility="denied"]',
            'Closure Analytics needs ROLE_PARTICIPANT.',
        );
        self::assertSelectorExists('[data-testid="nav-inspector-menu-statistics"] [data-nav-inspector-item="closures-overview"] [data-visibility="hidden_parent"]');
        self::assertSelectorExists('[data-nav-inspector-item="closures"][data-nav-hidden][data-nav-issue]', 'The filter can find hidden entries.');
        self::assertSelectorNotExists('[data-nav-inspector-item="dashboard"] [data-visibility]', 'Visible entries carry no status.');
        self::assertSelectorExists('template[data-nav-details="closures"]', 'Every entry has its properties for the side panel.');
    }

    public function testPermissionsCanBeEvaluatedForAnotherUser(): void
    {
        $client = $this->adminClient();
        UserFactory::new()->with(['username' => 'closure-tester', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']])->create();

        $client->request(Request::METHOD_GET, self::URL, ['user' => 'closure-tester']);

        self::assertSelectorTextContains('[data-testid="nav-inspector-form"]', 'Permissions are evaluated for the user "closure-tester".');
        self::assertSelectorExists('[data-nav-inspector-item="closures"]:not([data-nav-hidden])');
    }

    public function testAnUnknownUserFallsBackToTheCurrentAdmin(): void
    {
        $this->adminClient()->request(Request::METHOD_GET, self::URL, ['user' => 'nobody-here']);

        self::assertSelectorTextContains('[data-testid="nav-inspector-unknown-user"]', 'There is no user "nobody-here".');
        // Evaluated for the admin, who sees Closure Analytics (#1350).
        self::assertSelectorExists('[data-nav-inspector-item="closures"]:not([data-nav-hidden])');
    }

    public function testTheRouteCheckShowsTheActivePathAndTheRuleInEveryMenu(): void
    {
        $this->adminClient()->request(Request::METHOD_GET, self::URL, [
            'route' => 'app_stats_insights_dimension',
            'parameters' => 'dimension=occasions',
        ]);

        self::assertSelectorTextContains('[data-nav-inspector-route-menu="statistics"] [data-nav-inspector-path]', 'insights-dimension-occasions');
        self::assertSelectorExists('[data-nav-inspector-route-menu="statistics"] [data-nav-inspector-rule="route"]');
        self::assertSelectorTextContains('[data-nav-inspector-route-menu="primary"] [data-nav-inspector-path]', 'statistics');
        self::assertSelectorExists('[data-nav-inspector-route-menu="primary"] [data-nav-inspector-rule="sidebar_menu"]');
        self::assertSelectorTextContains('[data-nav-inspector-route-menu="explore"]', 'No entry is active.');
        self::assertSelectorExists('[data-testid="nav-inspector-menu-statistics"] [data-nav-inspector-item="insights-dimensions"][data-nav-on-path]');
        self::assertSelectorExists('[data-testid="nav-inspector-menu-statistics"] [data-nav-inspector-item="insights-dimension-occasions"] [data-nav-inspector-active]');
        self::assertSelectorExists('#nav-inspector-path-statistics:checked', 'With a checked route the tree starts focused on the path.');
    }

    public function testOnlyAdminsHaveAccess(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asParticipant()->create());

        $client->request(Request::METHOD_GET, self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        return $client;
    }
}
