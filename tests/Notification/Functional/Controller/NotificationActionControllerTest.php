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

    public function testOverviewOffersSelectionAndRemoveAll(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 2);
        NotificationFactory::createMany(2, ['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');

        self::assertCount(2, $crawler->filter('[data-testid="notification-item-select"][form="notification-bulk-form"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-select-all"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-bulk-read"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-bulk-remove"][data-controller="confirm-submit"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-remove-all"][data-bs-target="#notification-remove-all-modal"]'));
        self::assertCount(1, $crawler->filter('#notification-remove-all-modal form[action="/notifications/remove-all"]'));
    }

    public function testEmptyInboxHasNoBulkActionsAndNoRemoveAll(): void
    {
        $client = self::createClient();
        $this->loginWithState($client, unread: 0);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');

        self::assertCount(0, $crawler->filter('[data-testid="notification-bulk-actions"]'));
        self::assertCount(0, $crawler->filter('[data-testid="notification-remove-all"]'));
    }

    public function testBulkReadMarksSelectedOwnEntriesAndReturnsToThePage(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 3);
        $entries = NotificationFactory::createMany(3, ['recipient' => $user]);
        $foreign = NotificationFactory::createOne(['recipient' => UserFactory::new()]);

        $token = $this->bulkToken($client, '/notifications?filter=unread');
        $client->request(Request::METHOD_POST, '/notifications/bulk', [
            '_token' => $token,
            '_target' => '/notifications?filter=unread',
            'bulk_action' => 'read',
            'ids' => [(string) $entries[0]->getId(), (string) $entries[1]->getId(), (string) $foreign->getId(), 'abc', '0'],
        ]);

        self::assertResponseRedirects('/notifications?filter=unread');
        self::assertNotNull($this->readAt((int) $entries[0]->getId()));
        self::assertNotNull($this->readAt((int) $entries[1]->getId()));
        self::assertNull($this->readAt((int) $entries[2]->getId()));
        self::assertNull($this->readAt((int) $foreign->getId()));
        self::assertSame(1, $this->unreadCount($user));
        $client->followRedirect();
        self::assertSelectorTextContains('.alert', '2 notifications were marked as read.');
    }

    public function testBulkRemoveHidesSelectedEntries(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 2);
        $entries = NotificationFactory::createMany(2, ['recipient' => $user]);

        $client->request(Request::METHOD_POST, '/notifications/bulk', [
            '_token' => $this->bulkToken($client, '/notifications'),
            'bulk_action' => 'remove',
            'ids' => [(string) $entries[0]->getId()],
        ]);

        self::assertResponseRedirects('/notifications');
        self::assertNotNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$entries[0]->getId()]));
        self::assertNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$entries[1]->getId()]));
        self::assertSame(1, $this->unreadCount($user));
        $client->followRedirect();
        self::assertSelectorTextContains('.alert', '1 notification was removed.');
    }

    public function testBulkWithoutSelectionWarns(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        NotificationFactory::createOne(['recipient' => $user]);

        $client->request(Request::METHOD_POST, '/notifications/bulk', [
            '_token' => $this->bulkToken($client, '/notifications'),
            'bulk_action' => 'read',
        ]);

        self::assertResponseRedirects('/notifications');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert', 'No notifications were selected.');
        self::assertSame(1, $this->unreadCount($user));
    }

    public function testBulkRejectsUnknownActionTooManyIdsAndInvalidToken(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        $entry = NotificationFactory::createOne(['recipient' => $user]);
        $token = $this->bulkToken($client, '/notifications');

        $client->request(Request::METHOD_POST, '/notifications/bulk', ['_token' => $token, 'bulk_action' => 'archive', 'ids' => [(string) $entry->getId()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $client->request(Request::METHOD_POST, '/notifications/bulk', ['_token' => $token, 'bulk_action' => 'read', 'ids' => array_map(strval(...), range(1, 101))]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $client->request(Request::METHOD_POST, '/notifications/bulk', ['_token' => 'invalid', 'bulk_action' => 'read', 'ids' => [(string) $entry->getId()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertNull($this->readAt((int) $entry->getId()));
    }

    public function testRemoveAllHidesEveryOwnEntryThroughTheModal(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 3);
        NotificationFactory::createMany(3, ['recipient' => $user]);
        $foreign = NotificationFactory::createOne(['recipient' => UserFactory::new()]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');
        $client->submit($crawler->filter('#notification-remove-all-modal form')->form());

        self::assertResponseRedirects('/notifications');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ? AND removed_at IS NULL', [$user->getId()]));
        self::assertNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$foreign->getId()]));
        self::assertSame(0, $this->unreadCount($user));

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.alert', '3 notifications were removed.');
        self::assertCount(1, $crawler->filter('[data-testid="notification-inbox-empty"]'));

        $client->request(Request::METHOD_POST, '/notifications/remove-all', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testActionBarStartsWithActionsForAllEntriesAndHidesSelectionActions(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 1);
        NotificationFactory::createOne(['recipient' => $user]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications');

        self::assertCount(0, $crawler->filter('.page-header [data-testid="notification-read-all"], .page-header [data-testid="notification-remove-all"]'), 'the page header has no actions');
        self::assertCount(1, $crawler->filter('[data-testid="notification-all-actions"]:not(.d-none) [data-testid="notification-read-all"]'));
        self::assertStringContainsString('Remove all', $crawler->filter('[data-testid="notification-remove-all"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="notification-selection-actions"].d-none'));
        self::assertCount(1, $crawler->filter('[data-testid="notification-bulk-clear"]'));
        $card = $crawler->filter('[data-testid="notification-inbox"]');
        self::assertSame('checkbox-select-all notification-bulk', $card->attr('data-controller'));
        self::assertSame('%count% selected', $card->attr('data-notification-bulk-selected-label-value'));
        self::assertSame('/notifications/remove-all', $crawler->filter('#notification-remove-all-modal form')->attr('action'));
    }

    public function testUnreadFilterScopesRemoveAllToUnreadEntries(): void
    {
        $client = self::createClient();
        $user = $this->loginWithState($client, unread: 2);
        $unread = NotificationFactory::createMany(2, ['recipient' => $user]);
        $read = NotificationFactory::createOne(['recipient' => $user]);
        $this->connection()->executeStatement('UPDATE notification SET read_at = NOW() WHERE id = ?', [$read->getId()]);

        $crawler = $client->request(Request::METHOD_GET, '/notifications?filter=unread');
        self::assertStringContainsString('Remove all unread', $crawler->filter('[data-testid="notification-remove-all"]')->text());
        self::assertStringContainsString('Read notifications stay', $crawler->filter('#notification-remove-all-modal')->text());
        $client->submit($crawler->filter('#notification-remove-all-modal form')->form());

        self::assertResponseRedirects('/notifications?filter=unread');
        self::assertNotNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$unread[0]->getId()]));
        self::assertNotNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$unread[1]->getId()]));
        self::assertNull($this->connection()->fetchOne('SELECT removed_at FROM notification WHERE id = ?', [$read->getId()]));
        self::assertSame(0, $this->unreadCount($user));

        $client->followRedirect();
        self::assertSelectorTextContains('.alert', '2 notifications were removed.');
    }

    private function bulkToken(KernelBrowser $client, string $page): string
    {
        $crawler = $client->request(Request::METHOD_GET, $page);

        return (string) $crawler->filter('#notification-bulk-form input[name="_token"]')->attr('value');
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
