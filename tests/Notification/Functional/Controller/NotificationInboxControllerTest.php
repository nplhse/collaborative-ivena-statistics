<?php

declare(strict_types=1);

namespace App\Tests\Notification\Functional\Controller;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
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
        self::assertCount(0, $crawler->filter('[data-testid="notification-inbox-next"]'));
    }

    public function testOverviewPagesInStepsOfTwentyFive(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        $start = new \DateTimeImmutable('2026-10-01 00:00:00');
        for ($i = 0; $i < 27; ++$i) {
            NotificationFactory::createOne(['recipient' => $user, 'createdAt' => $start->modify('+'.$i.' hours')]);
        }

        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        self::assertCount(25, $crawler->filter('[data-testid="notification-item"]'));
        $next = $crawler->filter('[data-testid="notification-inbox-load-more"]');
        self::assertCount(1, $next);

        $page = $client->request(Request::METHOD_GET, (string) $next->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertCount(2, $page->filter('[data-testid="notification-item"]'));
        self::assertCount(0, $page->filter('[data-testid="notification-inbox-next"]'));
        self::assertStringStartsWith('notification-inbox-after-', (string) $page->filter('turbo-frame')->attr('id'));
    }

    public function testPageWithInvalidCursorStartsFromTheTop(): void
    {
        $client = self::createClient();
        $user = $this->user($client);
        NotificationFactory::createOne(['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications/page?cursor=not-a-cursor');

        self::assertResponseIsSuccessful();
        self::assertSame('notification-inbox', $crawler->filter('turbo-frame')->attr('id'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-item"]'));
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
