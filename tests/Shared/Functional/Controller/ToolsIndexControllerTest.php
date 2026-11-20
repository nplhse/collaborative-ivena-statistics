<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ToolsIndexControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use Factories;

    public function testGuestIsSentToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/tools');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testParticipantSeesIndicationReviewAndActiveNavigationEntry(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $client->request(Request::METHOD_GET, '/tools');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'Tools');
        self::assertSelectorExists('[data-testid="tools-group"][data-tools-category="matching"]');
        self::assertSelectorExists('[data-testid="tools-card"][data-tool-key="indication_review"] [data-testid="tools-card-link"][href="/tools/indication-review"]');
        self::assertSelectorTextContains('[data-tool-key="indication_review"]', 'Indication review');
        self::assertSelectorNotExists('[data-testid="tools-card-beta"]');
        self::assertSelectorExists('#navbar-menu li.nav-item.active[data-testid="nav-tools"] a[href="/tools"]');
    }

    public function testTopicBadgesLinkToTheFilterAndTheDropdownListsOnlyTopicsOfVisibleTools(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $crawler = $client->request(Request::METHOD_GET, '/tools');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/tools?topic=indications', '/tools?topic=allocations'],
            $crawler->filter('[data-tool-key="indication_review"] [data-testid="tools-card-topic"]')->each(static fn ($badge): string => (string) $badge->attr('href')),
        );
        // The indication review is the only tool of a participant, so the filter offers its two topics and "all".
        self::assertSame(
            ['', 'allocations', 'indications'],
            $crawler->filter('[data-testid="tools-topic-option"]')->each(static fn ($option): string => (string) $option->attr('data-tools-topic')),
        );
        self::assertSelectorTextContains('[data-testid="tools-topic-toggle"]', 'Topic: All topics');
    }

    public function testATopicShowsOnlyItsToolsAndMarksItInTheDropdown(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]));
        $client->request(Request::METHOD_GET, '/tools', ['topic' => 'imports']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['encounter_data_prep'],
            $client->getCrawler()->filter('[data-testid="tools-card"]')->each(static fn ($card): string => (string) $card->attr('data-tool-key')),
        );
        self::assertSelectorNotExists('[data-testid="tools-group"][data-tools-category="matching"]');
        self::assertSelectorTextContains('[data-testid="tools-topic-toggle"]', 'Topic: Imports');
        self::assertSelectorExists('[data-testid="tools-topic-option"].active[data-tools-topic="imports"][aria-current="true"]');
    }

    public function testATopicWithoutVisibleToolsIsIgnored(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $client->request(Request::METHOD_GET, '/tools', ['topic' => 'encounters']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-tool-key="indication_review"]');
        self::assertSelectorTextContains('[data-testid="tools-topic-toggle"]', 'Topic: All topics');
    }

    public function testUserWithoutToolsSeesEmptyStateAndNoNavigationEntry(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER']]));
        $client->request(Request::METHOD_GET, '/tools');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="tools-card"]');
        self::assertSelectorTextContains('[data-testid="tools-index"]', 'No tools are available for your account.');
        self::assertSelectorNotExists('[data-testid="nav-tools"]');
    }

    public function testNavigationEntryIsNotActiveOutsideTools(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $client->request(Request::METHOD_GET, '/explore');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#navbar-menu [data-testid="nav-tools"]');
        self::assertSelectorNotExists('#navbar-menu li.nav-item.active[data-testid="nav-tools"]');

        $hrefs = $client->getCrawler()->filter('#navbar-menu li.nav-item > a.nav-link')->each(
            static fn ($link): string => (string) $link->attr('href'),
        );
        $toolsIndex = array_search('/tools', $hrefs, true);
        self::assertIsInt($toolsIndex);
        self::assertGreaterThan(array_search('/import', $hrefs, true), $toolsIndex, 'Tools follows Import and Export.');
        self::assertLessThan(array_search('/blog', $hrefs, true), $toolsIndex, 'Tools precedes Blog and the content pages.');
    }

    public function testGermanPageHasNoMissingTranslations(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/tools', server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-tool-key="indication_review"]', 'Indikations-Review');
        $this->assertNoMissingTranslations($client->getProfile());
    }
}
