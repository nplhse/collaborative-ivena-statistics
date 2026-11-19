<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Navigation;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The explore sidebar (ADR 035, step 6): groups of the former Explore dropdowns, active entries for lists
 * and detail pages, no carried filters, and no sidebar for a user who may only open their own profile.
 */
#[ResetDatabase]
final class ExploreSidebarTest extends WebTestCase
{
    use Factories;

    public function testAParticipantSeesTheGroupsInTheOrderOfTheFormerDropdowns(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital', ['q' => 'Klinik'], server: ['HTTP_Accept-Language' => 'en']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#navbar-menu [data-testid="nav-explore"].active');
        self::assertSelectorExists('[data-testid="sidebar-entry-hospitals"].active');
        self::assertSame(
            [
                'explore-index',
                'allocations', 'mci-cases',
                'hospitals', 'states', 'dispatch-areas', 'users',
                'specialities', 'departments',
                'indications', 'indication-groups', 'assignments', 'infections', 'occasions', 'secondary-transports',
                'glossary',
            ],
            $crawler->filter('[data-testid="sidebar-explore"] [data-testid^="sidebar-entry-"]')->each(
                static fn (Crawler $node): string => substr((string) $node->attr('data-testid'), \strlen('sidebar-entry-')),
            ),
        );
        self::assertSame('/explore/state', $crawler->filter('[data-testid="sidebar-entry-states"] a')->attr('href'), 'Explore links carry no list filters.');
    }

    public function testAGlossaryPageOfAnotherContextMarksTheClassifications(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $client->request(Request::METHOD_GET, '/explore/glossary/mts');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="sidebar-entry-glossary"].active', 'The MTS glossary (Encounter) reaches the entry through the glossary registry.');
    }

    public function testTheOwnProfileWithoutParticipantRoleShowsNoSidebar(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/explore/user/'.$user->getUsername());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="sidebar-explore"]', 'Every explore entry needs ROLE_PARTICIPANT; an empty sidebar is not rendered.');
    }

    public function testAProfileMarksTheUsersEntryForAParticipant(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/explore/user/'.$user->getUsername());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="sidebar-entry-users"].active');
    }
}
