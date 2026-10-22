<?php

declare(strict_types=1);

namespace App\Tests\Notification\Functional\Controller;

use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Factory\NotificationRecipientStateFactory;
use App\Tests\Support\Foundry\DatabaseWebTestCase;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NotificationActionControllerTest extends DatabaseWebTestCase
{
    use InteractsWithAuthenticatedUser;

    private ?User $currentUser = null;

    public function testOpenMarksReadAndRedirectsToTheStoredRoute(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        $notification = NotificationFactory::createOne(['recipient' => $user, 'actionRoute' => 'app_settings_index']);

        $client->request(Request::METHOD_GET, '/notifications/'.$notification->getId().'/open');

        self::assertResponseRedirects('/settings');
        self::assertNotNull($this->readAt((int) $notification->getId()));
        self::assertSame(0, $this->unreadCount($user));
    }

    public function testOpenWithoutTargetFallsBackToTheOverview(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        $notification = NotificationFactory::createOne(['recipient' => $user, 'actionRoute' => 'app_route_that_was_removed']);

        $client->request(Request::METHOD_GET, '/notifications/'.$notification->getId().'/open');

        self::assertResponseRedirects('/notifications');
    }

    public function testOtherUsersEntriesAreNotFound(): void
    {
        $client = self::createClient();
        $this->currentUser = $this->loginWithState($client, unread: 0);
        $foreign = NotificationFactory::createOne(['recipient' => UserFactory::new(), 'actionRoute' => 'app_settings_index']);
        $id = (int) $foreign->getId();

        $client->request(Request::METHOD_GET, '/notifications/'.$id.'/open');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertNull($this->tokenFromOverview($client, $id), 'foreign entries are not rendered, so there is no form for them');

        $own = NotificationFactory::createOne(['recipient' => $this->currentUser]);
        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        $readToken = (string) $crawler->filter('[data-notification-id="'.$own->getId().'"] [data-testid="notification-item-read"]')->form()->get('_token')->getValue();
        $removeToken = (string) $crawler->filter('[data-notification-id="'.$own->getId().'"] [data-testid="notification-item-remove"]')->form()->get('_token')->getValue();

        $client->request(Request::METHOD_POST, '/notifications/'.$id.'/read', ['_token' => $readToken]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request(Request::METHOD_POST, '/notifications/'.$id.'/remove', ['_token' => $removeToken]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertNull($this->readAt($id));
        self::assertNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$id]));
    }

    public function testMarkReadThroughTheOverviewForm(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        $notification = NotificationFactory::createOne(['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        $client->submit($crawler->filter('[data-notification-id="'.$notification->getId().'"] [data-testid="notification-item-read"]')->form());

        self::assertResponseRedirects('/notifications');
        self::assertNotNull($this->readAt((int) $notification->getId()));
        self::assertSame(0, $this->unreadCount($user));
    }

    public function testMarkAllReadReturnsToTheGivenPage(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 2);
        NotificationFactory::createMany(2, ['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications/dropdown?target=/settings');
        $client->submit($crawler->filter('[data-testid="notification-read-all"]')->form());

        self::assertResponseRedirects('/settings');
        self::assertSame(0, $this->unreadCount($user));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ? AND read_at IS NULL', [$user->getId()]));
    }

    public function testRemoveHidesTheEntry(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        $notification = NotificationFactory::createOne(['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        $client->submit($crawler->filter('[data-testid="notification-item-remove"]')->form());
        self::assertResponseRedirects('/notifications');

        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="notification-inbox-empty"]'));
        self::assertNotNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$notification->getId()]));
        self::assertSame(0, $this->unreadCount($user));
    }

    public function testActionsRejectInvalidCsrfTokens(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        $notification = NotificationFactory::createOne(['recipient' => $user]);
        $id = (int) $notification->getId();

        foreach (['/notifications/'.$id.'/read', '/notifications/read-all', '/notifications/'.$id.'/remove'] as $path) {
            $client->request(Request::METHOD_POST, $path, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $path);
        }

        self::assertNull($this->readAt($id));
        self::assertSame(1, $this->unreadCount($user));
    }

    public function testActionsReturnToTheOverviewForForeignTargets(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        NotificationFactory::createOne(['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        $form = $crawler->filter('[data-testid="notification-read-all"]')->form(['_target' => 'https://example.org/']);
        $client->submit($form);

        self::assertResponseRedirects('/notifications');
    }

    private function loginWithState(KernelBrowser $client, int $unread): User
    {
        $user = $this->loginAsRoleUser($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'unreadCount' => $unread]);

        return $user;
    }

    private function tokenFromOverview(KernelBrowser $client, int $notificationId): ?string
    {
        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        $field = $crawler->filter('[data-notification-id="'.$notificationId.'"] input[name="_token"]');

        return 0 === $field->count() ? null : (string) $field->attr('value');
    }

    private function readAt(int $notificationId): mixed
    {
        return $this->connection()->fetchOne('SELECT read_at FROM notification WHERE id = ?', [$notificationId]);
    }

    private function unreadCount(User $user): int
    {
        return (int) $this->connection()->fetchOne('SELECT unread_count FROM notification_recipient_state WHERE user_id = ?', [$user->getId()]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
