<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Layout;

use App\Notification\Infrastructure\Factory\NotificationRecipientStateFactory;
use App\Tests\Support\Foundry\DatabaseWebTestCase;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class UserMenuTest extends DatabaseWebTestCase
{
    use InteractsWithAuthenticatedUser;

    public function testRoleUserSeesOwnProfileButNoHospitals(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['user-menu-profile', 'user-menu-notifications', 'user-menu-settings', 'user-menu-logout'],
            $this->menuEntries($crawler),
        );
        self::assertSame(
            '/explore/user/'.$user->getPublicIdString(),
            $crawler->filter('[data-testid="user-menu-profile"]')->attr('href'),
        );
    }

    public function testParticipantSeesProfileAndHospitals(): void
    {
        $client = $this->createClientAsParticipant();

        $crawler = $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['user-menu-profile', 'user-menu-hospitals', 'user-menu-notifications', 'user-menu-settings', 'user-menu-logout'],
            $this->menuEntries($crawler),
        );
    }

    public function testAdminSeesBackendInTheLastSection(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]));

        $crawler = $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['user-menu-profile', 'user-menu-hospitals', 'user-menu-notifications', 'user-menu-settings', 'user-menu-backend', 'user-menu-logout'],
            $this->menuEntries($crawler),
        );
    }

    public function testNotificationsBadgeIsHiddenWithoutUnreadEntries(): void
    {
        $client = $this->createClientAsRoleUser();

        $crawler = $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        $badge = $crawler->filter('[data-testid="user-menu-notifications-badge"]');
        self::assertCount(1, $badge, 'the badge is rendered hidden so polling can reveal it');
        self::assertStringContainsString('d-none', (string) $badge->attr('class'));
        self::assertSame('0', $badge->attr('data-unread-count'));
    }

    public function testNotificationsBadgeShowsTheUnreadCount(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'unreadCount' => 3]);

        $crawler = $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        $badge = $crawler->filter('[data-testid="user-menu-notifications-badge"]');
        self::assertStringNotContainsString('d-none', (string) $badge->attr('class'));
        self::assertSame('3', trim($badge->text()));
        self::assertSame('Notifications, 3 unread', $crawler->filter('[data-testid="user-menu-notifications"]')->attr('aria-label'));
        self::assertSame('3', $crawler->filter('[data-testid="notification-bell-badge"]')->attr('data-unread-count'), 'bell and menu agree');
    }

    public function testNotificationsBadgeCapsLargeCounts(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'unreadCount' => 150]);

        $crawler = $client->request(Request::METHOD_GET, '/settings');

        self::assertResponseIsSuccessful();
        self::assertSame('99+', trim($crawler->filter('[data-testid="user-menu-notifications-badge"]')->text()));
        self::assertSame('150', $crawler->filter('[data-testid="user-menu-notifications-badge"]')->attr('data-unread-count'));
    }

    /**
     * @return list<string>
     */
    private function menuEntries(Crawler $crawler): array
    {
        return array_values(array_map(
            static fn (?string $id): string => (string) $id,
            $crawler->filter('[data-testid="user-menu"] a.dropdown-item[data-testid]')->each(
                static fn (Crawler $node): ?string => $node->attr('data-testid'),
            ),
        ));
    }
}
