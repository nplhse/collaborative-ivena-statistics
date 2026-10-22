<?php

declare(strict_types=1);

namespace App\Tests\Notification\Functional\Controller;

use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationPriority;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\Notification\Infrastructure\Factory\NotificationRecipientStateFactory;
use App\Tests\Support\Foundry\DatabaseWebTestCase;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class NotificationPollControllerTest extends DatabaseWebTestCase
{
    use InteractsWithAuthenticatedUser;

    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/notifications/poll');

        self::assertResponseRedirects();
    }

    public function testAnswersNoContentWhenNothingChanged(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'version' => 4, 'unreadCount' => 1]);

        $client->request(Request::METHOD_GET, '/notifications/poll?version=4');

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    public function testChangedVersionUpdatesTheBadgeWithoutToasts(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'version' => 5, 'unreadCount' => 2]);

        $data = $this->poll($client, 4);

        self::assertSame(5, $data['version']);
        self::assertSame(2, $data['unreadCount']);
        self::assertSame('Notifications, 2 unread', $data['badgeLabel']);
        self::assertSame([], $data['toasts']);
        self::assertNull($data['more']);
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM analytics_request WHERE route_name = 'app_notification_poll'"), 'polls are not tracked as page views');
    }

    public function testClaimsPendingToastsOnceEvenWithAnEqualVersion(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'version' => 3, 'unreadCount' => 1]);
        $notification = $this->pendingToast($user, [
            'priority' => NotificationPriority::High,
            'titleKey' => 'import.failed.title',
            'messageKey' => 'import.failed.message',
            'parameters' => ['importName' => 'Januar', 'hospitalName' => 'Klinikum Test', 'reason' => 'no_rows'],
            'actionRoute' => 'app_notification_index',
        ]);

        $data = $this->poll($client, 3);

        self::assertSame([[
            'id' => $notification->getId(),
            'title' => 'Import "Januar" failed',
            'message' => 'Klinikum Test: The file contains no rows.',
            'url' => '/notifications/'.$notification->getId().'/open',
            'severity' => 'warning',
            'important' => true,
        ]], $data['toasts']);

        $client->request(Request::METHOD_GET, '/notifications/poll?version=3');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, 'a claimed toast is not handed out again');
    }

    public function testShowsThreeToastsAndSummarizesTheRestAndHidesInvisibleOnes(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        NotificationRecipientStateFactory::createOne(['user' => $user, 'version' => 1]);
        for ($i = 0; $i < 5; ++$i) {
            $this->pendingToast($user, ['priority' => NotificationPriority::Low]);
        }
        $this->pendingToast($user, ['accessScope' => NotificationAccessScope::Role, 'requiredRole' => 'ROLE_ADMIN']);

        $data = $this->poll($client, 1);

        self::assertCount(3, $data['toasts']);
        self::assertSame(['secondary', false, null], [$data['toasts'][0]['severity'], $data['toasts'][0]['important'], $data['toasts'][0]['url']]);
        self::assertSame(['label' => '2 more notifications', 'url' => '/notifications?filter=unread'], $data['more']);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE toast_claimed_at IS NULL'), 'hidden toasts are claimed but not shown');
    }

    public function testRejectsPollsBeyondTheRateLimit(): void
    {
        $client = self::createClient();
        $user = $this->loginAsRoleUser($client);
        $limiter = self::getContainer()->get('limiter.notification_poll');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        $bucket = $limiter->create('notification_poll_'.$user->getId());
        while ($bucket->consume()->isAccepted()) {
            // Use up the bucket.
        }

        $client->request(Request::METHOD_GET, '/notifications/poll?version=0');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(KernelBrowser $client, int $version): array
    {
        $client->request(Request::METHOD_GET, '/notifications/poll?version='.$version);
        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $data = json_decode((string) $client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function pendingToast(User $user, array $attributes = []): \App\Notification\Domain\Entity\Notification
    {
        return NotificationFactory::createOne([
            'recipient' => $user,
            'toastPendingUntil' => new \DateTimeImmutable('+5 minutes'),
            ...$attributes,
        ]);
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return self::getContainer()->get(\Doctrine\DBAL\Connection::class);
    }
}
