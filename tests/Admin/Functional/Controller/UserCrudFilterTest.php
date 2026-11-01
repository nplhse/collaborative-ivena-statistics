<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Review of recent registrations and accounts without participant permissions (#966).
 */
#[ResetDatabase]
final class UserCrudFilterTest extends WebTestCase
{
    use Factories;

    /** Registered in October 2026 without participant permissions, newest first. */
    private const string RECENT_NON_PARTICIPANTS_NEWEST_FIRST = '/admin/user?'
        .'filters[createdAt][comparison]=between'
        .'&filters[createdAt][value]=2026-10-01T00:00'
        .'&filters[createdAt][value2]=2026-10-31T23:59'
        .'&filters[participantPermissions]=0'
        .'&sort[createdAt]=DESC';

    public function testIndexShowsSortableCreatedAtColumn(): void
    {
        $client = self::createClient();
        $this->createUser('older', '2026-03-01 08:00');
        $this->createUser('newer', '2026-09-01 08:00');
        $client->loginUser($this->createAdmin());

        $crawler = $client->request(Request::METHOD_GET, '/admin/user?sort[createdAt]=DESC');
        self::assertResponseIsSuccessful();

        self::assertSame('Created at', trim($crawler->filter('table thead th[data-column="createdAt"]')->text()));
        self::assertCount(1, $crawler->filter('table thead th[data-column="createdAt"] a'), 'Column header is a sort link.');
        self::assertSame(['newer', 'older', 'filter-admin'], $this->usernames($crawler));
        self::assertStringContainsString('01.09.2026 08:00', $crawler->filter('table tbody')->text());
    }

    public function testCombinedFiltersListRecentNonParticipantsNewestFirst(): void
    {
        $client = self::createClient();
        $this->createUser('old-user', '2026-01-15 10:00');
        $this->createUser('recent-user-a', '2026-10-10 10:00');
        $this->createUser('recent-user-b', '2026-10-20 10:00');
        $this->createUser('recent-participant', '2026-10-15 10:00', [UserRole::PARTICIPANT]);
        $this->createUser('recent-admin', '2026-10-12 10:00', [UserRole::ADMIN]);
        $this->createUser('november-user', '2026-11-01 09:00');
        $client->loginUser($this->createAdmin());

        $crawler = $client->request(Request::METHOD_GET, self::RECENT_NON_PARTICIPANTS_NEWEST_FIRST);
        self::assertResponseIsSuccessful();

        self::assertSame(['recent-user-b', 'recent-user-a'], $this->usernames($crawler));
    }

    public function testParticipantFilterCountsRolesInheritedFromAdmin(): void
    {
        $client = self::createClient();
        $this->createUser('plain-user', '2026-10-10 10:00');
        $this->createUser('participant', '2026-10-11 10:00', [UserRole::PARTICIPANT]);
        $this->createUser('other-admin', '2026-10-12 10:00', [UserRole::ADMIN]);
        $this->createUser('board-only', '2026-10-13 10:00', [UserRole::BOARD_MEMBER]);
        $client->loginUser($this->createAdmin());

        $crawler = $client->request(Request::METHOD_GET, '/admin/user?filters[participantPermissions]=1&sort[username]=ASC');
        self::assertResponseIsSuccessful();
        self::assertSame(['filter-admin', 'other-admin', 'participant'], $this->usernames($crawler));

        $crawler = $client->request(Request::METHOD_GET, '/admin/user?filters[participantPermissions]=0&sort[username]=ASC');
        self::assertResponseIsSuccessful();
        self::assertSame(['board-only', 'plain-user'], $this->usernames($crawler));
    }

    public function testFiltersAndSortingSurvivePagination(): void
    {
        $client = self::createClient();
        for ($day = 1; $day <= 22; ++$day) {
            $this->createUser(\sprintf('recent-%02d', $day), \sprintf('2026-10-%02d 12:00', $day));
        }
        $client->loginUser($this->createAdmin());

        $crawler = $client->request(Request::METHOD_GET, self::RECENT_NON_PARTICIPANTS_NEWEST_FIRST);
        self::assertResponseIsSuccessful();
        self::assertSame('recent-22', $this->usernames($crawler)[0]);

        $nextPage = $crawler->filter('.pagination a.page-link')->reduce(
            static fn (Crawler $link): bool => str_contains((string) $link->attr('href'), 'page=2'),
        );
        self::assertGreaterThan(0, $nextPage->count(), 'Second page link exists.');
        $this->assertKeepsReviewState((string) $nextPage->first()->attr('href'));

        $crawler = $client->click($nextPage->first()->link());
        self::assertResponseIsSuccessful();
        self::assertSame(['recent-02', 'recent-01'], $this->usernames($crawler));
    }

    public function testFiltersAndSortingSurviveReviewOfADetailPage(): void
    {
        $client = self::createClient();
        $target = $this->createUser('recent-user', '2026-10-10 10:00');
        $client->loginUser($this->createAdmin());

        $crawler = $client->request(Request::METHOD_GET, self::RECENT_NON_PARTICIPANTS_NEWEST_FIRST);
        $detailLink = $this->detailLink($crawler, $target);
        $this->assertKeepsReviewState($detailLink);

        $crawler = $client->request(Request::METHOD_GET, $detailLink);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('10.10.2026 10:00', $crawler->text());

        $backToList = $crawler->filter('a.action-index');
        self::assertGreaterThan(0, $backToList->count());
        $this->assertKeepsReviewState((string) $backToList->first()->attr('href'));

        $crawler = $client->click($backToList->first()->link());
        self::assertResponseIsSuccessful();
        self::assertSame(['recent-user'], $this->usernames($crawler));
    }

    public function testDeletingFromTheDetailPageReturnsToTheFilteredList(): void
    {
        $client = self::createClient();
        $target = $this->createUser('spam-candidate', '2026-10-10 10:00');
        $this->createUser('other-recent-user', '2026-10-11 10:00');
        $targetId = $target->getId();
        $client->loginUser($this->createAdmin());

        $crawler = $client->request(Request::METHOD_GET, self::RECENT_NON_PARTICIPANTS_NEWEST_FIRST);
        $crawler = $client->request(Request::METHOD_GET, $this->detailLink($crawler, $target));
        self::assertResponseIsSuccessful();

        $deleteLink = $crawler->filter('a.action-delete');
        self::assertCount(1, $deleteLink);
        $client->request(Request::METHOD_POST, (string) $deleteLink->attr('href'), [
            'token' => $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value'),
        ]);

        self::assertResponseRedirects();
        $this->assertKeepsReviewState((string) $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(['other-recent-user'], $this->usernames($crawler));

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(self::getContainer()->get(UserRepository::class)->find($targetId));
    }

    public function testBlockedDeleteReturnsToTheFilteredListAndKeepsTheUser(): void
    {
        $client = self::createClient();
        $owner = $this->createUser('hospital-owner', '2026-10-10 10:00');
        HospitalFactory::createOne(['owner' => $owner]);
        $ownerId = $owner->getId();
        $client->loginUser($this->createAdmin());

        $crawler = $client->request(Request::METHOD_GET, self::RECENT_NON_PARTICIPANTS_NEWEST_FIRST);
        $detailUrl = $this->detailLink($crawler, $owner);
        $crawler = $client->request(Request::METHOD_GET, $detailUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.action-delete'), 'Delete is not offered to hospital owners.');

        // The delete URL as EasyAdmin builds it from the filtered detail page.
        $client->request(Request::METHOD_POST, str_replace('/admin/user/'.$ownerId.'?', '/admin/user/'.$ownerId.'/delete?', html_entity_decode($detailUrl)), [
            'token' => $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value'),
        ]);

        self::assertResponseRedirects();
        $this->assertKeepsReviewState((string) $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('This account cannot be deleted', $crawler->text());
        self::assertSame(['hospital-owner'], $this->usernames($crawler));

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertInstanceOf(User::class, self::getContainer()->get(UserRepository::class)->find($ownerId));
    }

    /**
     * @param list<string> $extraRoles
     */
    private function createUser(string $username, string $createdAt, array $extraRoles = []): User
    {
        return UserFactory::createOne([
            'username' => $username,
            'roles' => array_merge([UserRole::USER], $extraRoles),
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
    }

    private function createAdmin(): User
    {
        return $this->createUser('filter-admin', '2025-01-01 00:00', [UserRole::ADMIN]);
    }

    /**
     * @return list<string>
     */
    private function usernames(Crawler $crawler): array
    {
        return array_values($crawler->filter('table tbody td[data-column="username"]')->each(
            static fn (Crawler $cell): string => trim($cell->text()),
        ));
    }

    private function detailLink(Crawler $indexPage, User $user): string
    {
        $links = $indexPage->filter('table tbody a.action-detail')->reduce(
            static fn (Crawler $link): bool => 1 === preg_match('#/admin/user/'.$user->getId().'(\?|$)#', (string) $link->attr('href')),
        );
        self::assertCount(1, $links, 'Detail link for '.$user->getUsername());

        return (string) $links->attr('href');
    }

    private function assertKeepsReviewState(string $url): void
    {
        $query = [];
        parse_str((string) parse_url(html_entity_decode($url), \PHP_URL_QUERY), $query);

        self::assertSame([
            'createdAt' => [
                'comparison' => 'between',
                'value' => '2026-10-01T00:00',
                'value2' => '2026-10-31T23:59',
            ],
            'participantPermissions' => '0',
        ], $query['filters'] ?? null, 'Filters kept in '.$url);
        self::assertSame(['createdAt' => 'DESC'], $query['sort'] ?? null, 'Sorting kept in '.$url);
    }
}
