<?php

declare(strict_types=1);

namespace App\Tests\Notification\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Notification\Application\Inbox\InboxCursor;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Factory\NotificationRecipientStateFactory;
use App\Tests\Support\Foundry\DatabaseWebTestCase;
use App\Tests\Support\Notification\NotificationTestState;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class NotificationInboxControllerTest extends DatabaseWebTestCase
{
    use InteractsWithAuthenticatedUser;

    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/notifications');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $client->request(Request::METHOD_GET, '/notifications/dropdown');
        self::assertResponseRedirects();
    }

    public function testEmptyInbox(): void
    {
        $client = $this->createClientAsRoleUser();

        $crawler = $client->request(Request::METHOD_GET, '/notifications');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="notification-inbox-empty"]'));
        self::assertCount(0, $crawler->filter('[data-testid="notification-read-all"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-bell"]'));
        self::assertCount(0, $crawler->filter('[data-testid="notification-bell-badge"]'));
        self::assertCount(1, $crawler->filter('[data-testid="user-menu-notifications"]'));
        self::assertStringNotContainsString('notification.title', $crawler->filter('.breadcrumb')->text(), 'the breadcrumb label is translated');
    }

    public function testOverviewShowsOwnEntriesNewestFirstWithBadge(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'unreadCount' => 2]);
        $older = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-10-20 10:00:00')]);
        $newer = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-10-21 10:00:00'), 'actionRoute' => 'app_notification_index']);
        NotificationFactory::createOne(['createdAt' => new \DateTimeImmutable('2026-10-22 10:00:00')]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $newer->getId(), (string) $older->getId()], $this->itemIds($crawler));
        self::assertSame('2', $crawler->filter('[data-testid="notification-bell-badge"]')->attr('data-unread-count'));
        self::assertSame('', trim($crawler->filter('[data-testid="notification-bell-badge"]')->text()), 'the badge is a dot, the count is in the label');
        self::assertSame('1', $crawler->filter('[data-testid="notification-bell"]')->attr('data-notification-version'));
        self::assertCount(1, $crawler->filter('[data-notification-id="'.$newer->getId().'"] [data-testid="notification-item-link"]'));
        self::assertCount(0, $crawler->filter('[data-notification-id="'.$older->getId().'"] [data-testid="notification-item-link"]'));
        self::assertCount(2, $crawler->filter('[data-testid="notification-item-read"]'));
        self::assertCount(2, $crawler->filter('[data-testid="notification-item-remove"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-read-all"]'));
        self::assertCount(0, $crawler->filter('[data-testid="notification-inbox-pagination"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-retention-notice"]'));
        self::assertStringContainsString('30 days', $crawler->filter('[data-testid="notification-retention-notice"]')->text());
    }

    public function testOverviewPagesInStepsOfTwentyFiveWithNewerAndOlderLinks(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        $start = new \DateTimeImmutable('2026-10-01 00:00:00');
        for ($i = 0; $i < 27; ++$i) {
            NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $start->modify('+'.$i.' hours')]);
        }

        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        self::assertCount(25, $crawler->filter('[data-testid="notification-item"]'));
        $firstPageIds = $this->itemIds($crawler);
        self::assertStringContainsString('disabled', (string) $crawler->filter('[data-testid="notification-inbox-newer"]')->closest('li')?->attr('class'));
        $older = (string) $crawler->filter('[data-testid="notification-inbox-older"]')->attr('href');
        self::assertStringStartsWith('/notifications?before=', $older);

        $page = $client->request(Request::METHOD_GET, $older);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $page->filter('[data-testid="notification-item"]'));
        self::assertStringContainsString('disabled', (string) $page->filter('[data-testid="notification-inbox-older"]')->closest('li')?->attr('class'));
        self::assertSame($older, $page->filter('[data-testid="notification-item"] input[name="_target"]')->first()->attr('value'), 'actions return to the same page');
        $newer = (string) $page->filter('[data-testid="notification-inbox-newer"]')->attr('href');
        self::assertStringStartsWith('/notifications?after=', $newer);

        $back = $client->request(Request::METHOD_GET, $newer);
        self::assertSame($firstPageIds, $this->itemIds($back));
        self::assertStringContainsString('disabled', (string) $back->filter('[data-testid="notification-inbox-newer"]')->closest('li')?->attr('class'));
    }

    public function testPageThatBecameEmptyLinksBackToTheNewestEntries(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        $notification = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => new \DateTimeImmutable('2026-10-01 10:00:00')]);
        $cursor = new InboxCursor(new \DateTimeImmutable('2026-10-01 10:00:00'), (int) $notification->getId());

        $crawler = $client->request(Request::METHOD_GET, '/notifications?filter=unread&before='.$cursor->encode());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="notification-inbox-page-empty"]'));
        self::assertSame('/notifications?filter=unread', $crawler->filter('[data-testid="notification-inbox-page-empty"] a')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="notification-inbox-pagination"]'));
    }

    public function testUnreadFilterShowsOnlyUnreadEntriesAndKeepsTheFilter(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'unreadCount' => 26]);
        $start = new \DateTimeImmutable('2026-10-01 00:00:00');
        for ($i = 0; $i < 26; ++$i) {
            NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $start->modify('+'.$i.' hours')]);
        }
        $read = NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $start->modify('+2 days')]);
        new NotificationTestState(self::getContainer()->get(Connection::class))->markRead($read, $start->modify('+3 days'));

        $crawler = $client->request(Request::METHOD_GET, '/notifications?filter=unread');

        self::assertResponseIsSuccessful();
        $unreadTab = $crawler->filter('[data-testid="notification-filter-unread"]');
        self::assertStringContainsString('active', (string) $unreadTab->attr('class'));
        self::assertSame('page', $unreadTab->attr('aria-current'));
        self::assertStringNotContainsString('active', (string) $crawler->filter('[data-testid="notification-filter-all"]')->attr('class'));
        self::assertSame('26', trim($unreadTab->filter('.badge')->text()));
        self::assertCount(25, $crawler->filter('[data-testid="notification-item"]'));
        self::assertCount(0, $crawler->filter('[data-notification-id="'.$read->getId().'"]'));
        self::assertSame('/notifications?filter=unread', $crawler->filter('form:has([data-testid="notification-read-all"]) input[name="_target"]')->attr('value'));
        self::assertSame('/notifications?filter=unread', $crawler->filter('[data-testid="notification-item"] input[name="_target"]')->first()->attr('value'));

        $next = (string) $crawler->filter('[data-testid="notification-inbox-older"]')->attr('href');
        self::assertStringStartsWith('/notifications?filter=unread&before=', $next);
        $page = $client->request(Request::METHOD_GET, $next);
        self::assertCount(1, $page->filter('[data-testid="notification-item"]'));

        $all = $client->request(Request::METHOD_GET, '/notifications');
        self::assertStringContainsString('active', (string) $all->filter('[data-testid="notification-filter-all"]')->attr('class'));
        self::assertCount(1, $all->filter('[data-notification-id="'.$read->getId().'"]'));
    }

    public function testUnreadFilterWithoutUnreadEntriesShowsItsOwnEmptyState(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        $read = NotificationFactory::createOne(['recipient' => $user]);
        new NotificationTestState(self::getContainer()->get(Connection::class))->markRead($read, new \DateTimeImmutable());

        $crawler = $client->request(Request::METHOD_GET, '/notifications?filter=unread');

        self::assertCount(1, $crawler->filter('[data-testid="notification-inbox-empty"]'));
        self::assertStringContainsString('All caught up', $crawler->filter('[data-testid="notification-inbox-empty"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="notification-filter-unread"] .badge'));
    }

    public function testInvalidCursorStartsFromTheNewestEntries(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        NotificationFactory::createOne(['recipient' => $user]);

        foreach (['/notifications?before=not-a-cursor', '/notifications?after=not-a-cursor'] as $url) {
            $crawler = $client->request(Request::METHOD_GET, $url);

            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-testid="notification-item"]'));
            self::assertSame('/notifications', $crawler->filter('[data-testid="notification-item"] input[name="_target"]')->first()->attr('value'));
        }
    }

    public function testDropdownShowsFiveNewestAndKeepsTheReturnPath(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'unreadCount' => 6]);
        $start = new \DateTimeImmutable('2026-10-01 00:00:00');
        $ids = [];
        for ($i = 0; $i < 6; ++$i) {
            $ids[] = (string) NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $start->modify('+'.$i.' hours')])->getId();
        }

        $crawler = $client->request(Request::METHOD_GET, '/notifications/dropdown?target=/statistics');

        self::assertResponseIsSuccessful();
        self::assertSame(\array_slice(array_reverse($ids), 0, 5), $this->itemIds($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="notification-item-remove"]'), 'the dropdown is compact');
        self::assertCount(5, $crawler->filter('[data-testid="notification-item-read"]'), 'unread entries offer a hover action');
        self::assertCount(1, $crawler->filter('[data-testid="notification-read-all"][aria-label]'), 'mark all as read is an icon button with a label');
        self::assertSame('/statistics', $crawler->filter('[data-testid="notification-dropdown"] input[name="_target"]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-dropdown-show-all"]'));
        self::assertSame('6', trim($crawler->filter('[data-testid="notification-dropdown-unread-count"]')->text()));
        self::assertCount(5, $crawler->filter('[data-testid="notification-item"] .status-dot.bg-primary'), 'unread entries use the primary color');
    }

    public function testDropdownRejectsForeignReturnTargets(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'unreadCount' => 1]);
        NotificationFactory::createOne(['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications/dropdown?target=//example.org');

        self::assertSame('/notifications', $crawler->filter('input[name="_target"]')->attr('value'));
    }

    public function testDropdownWithoutEntries(): void
    {
        $client = $this->createClientAsRoleUser();

        $crawler = $client->request(Request::METHOD_GET, '/notifications/dropdown');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="notification-dropdown-empty"]'));
        self::assertCount(0, $crawler->filter('[data-testid="notification-dropdown-unread-count"]'));
        self::assertCount(0, $crawler->filter('[data-testid="notification-read-all"]'));
    }

    public function testRedactsRevokedHospitalsAndHidesEntriesWithoutRole(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        $hospital = HospitalFactory::createOne(['owner' => UserFactory::new(), 'name' => 'Klinikum Geheim']);
        $redacted = NotificationFactory::createOne([
            'recipient' => $user,
            'accessScope' => NotificationAccessScope::Hospital,
            'scopeReferenceId' => $hospital->getId(),
            'parameters' => ['hospitalName' => 'Klinikum Geheim'],
            'titleKey' => '{hospitalName}',
            'actionRoute' => 'app_notification_index',
        ]);
        $hidden = NotificationFactory::createOne(['recipient' => $user, 'accessScope' => NotificationAccessScope::Role, 'requiredRole' => 'ROLE_ADMIN']);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $redacted->getId()], $this->itemIds($crawler));
        self::assertStringNotContainsString('Klinikum Geheim', (string) $client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-testid="notification-item-link"]'));
        self::assertCount(0, $crawler->filter('[data-notification-id="'.$hidden->getId().'"]'));
    }

    public function testRemovedEntriesAreNotListed(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        $removed = NotificationFactory::createOne(['recipient' => $user]);
        new NotificationTestState(self::getContainer()->get(Connection::class))->markRemoved($removed, new \DateTimeImmutable());

        $crawler = $client->request(Request::METHOD_GET, '/notifications');

        self::assertCount(1, $crawler->filter('[data-testid="notification-inbox-empty"]'));
    }

    private function user(KernelBrowser $client): User
    {
        return $this->loginAsRoleUser($client);
    }

    /**
     * @return list<string>
     */
    private function itemIds(Crawler $crawler): array
    {
        return array_values(array_map(strval(...), $crawler->filter('[data-testid="notification-item"]')->each(
            static fn (Crawler $item): string => (string) $item->attr('data-notification-id'),
        )));
    }
}
