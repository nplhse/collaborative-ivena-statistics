<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\EventSubscriber;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Notification\NotificationPreferenceTestState;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Address;

/**
 * A registration notifies the administrators. Which administrators count (enabled, verified) is in
 * `AdminNotificationRecipientResolverTest`.
 */
final class UserRegisteredNotificationSubscriberTest extends DatabaseKernelTestCase
{
    public function testRegistrationNotifiesEveryAdministratorWithoutTheEmailAddress(): void
    {
        $first = UserFactory::new()->asAdmin()->create();
        $second = UserFactory::new()->asAdmin()->create();
        $user = UserFactory::createOne(['username' => 'notify-me', 'email' => 'notify-me@example.test']);
        $userId = (int) $user->getId();

        $this->dispatch($userId);

        $expected = [
            'type' => 'admin.user_registered',
            'category' => 'administration',
            'access_scope' => 'role',
            'required_role' => 'ROLE_ADMIN',
            'action_route' => 'app_admin_dashboard_user_detail',
            'action_parameters' => ['entityId' => $userId],
            // No email address is stored.
            'parameters' => ['userId' => $userId, 'username' => 'notify-me'],
        ];
        self::assertSame([
            ['recipient_id' => $first->getId()] + $expected,
            ['recipient_id' => $second->getId()] + $expected,
        ], $this->notifications());
    }

    public function testAdministratorWhoSwitchedTheTypeOffGetsNothing(): void
    {
        $recipient = UserFactory::new()->asAdmin()->create();
        $adminWhoSwitchedItOff = UserFactory::new()->asAdmin()->create();
        $preferences = new NotificationPreferenceTestState($this->connection());
        $preferences->switchOff($adminWhoSwitchedItOff, 'admin.user_registered', 'in_app');
        $preferences->switchOff($adminWhoSwitchedItOff, 'admin.user_registered', 'email');
        $user = UserFactory::createOne();

        $this->dispatch((int) $user->getId());

        self::assertSame([$recipient->getId()], array_column($this->notifications(), 'recipient_id'));
        self::assertEmailCount(1);
    }

    public function testTheSameRegistrationNotifiesOnlyOnce(): void
    {
        $recipient = UserFactory::new()->asAdmin()->create();
        $user = UserFactory::createOne();
        $this->dispatch((int) $user->getId());

        $this->dispatch((int) $user->getId());

        self::assertSame([$recipient->getId()], array_column($this->notifications(), 'recipient_id'));
        self::assertEmailCount(1);
    }

    public function testMailNamesTheNewUserAndLinksToTheNextSteps(): void
    {
        UserFactory::new()->asAdmin()->create(['email' => 'admin-notify@example.test']);
        $user = UserFactory::createOne(['username' => 'notify-me', 'email' => 'notify-me@example.test']);

        $this->dispatch((int) $user->getId());

        $email = $this->emailTo('admin-notify@example.test');
        self::assertSame('New user registration: notify-me', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('notify-me', $html);
        self::assertStringContainsString('/admin/user/'.$user->getId(), $html);
        self::assertStringContainsString('/admin/users/'.$user->getId().'/grant-participant', $html);
        self::assertStringContainsString('/notifications', $html);
        self::assertStringNotContainsString('notify-me@example.test', $html, 'the email does not repeat the new user\'s address');
    }

    public function testMailFallsBackToTheGenericEmailWhenTheUserIsGone(): void
    {
        $recipient = UserFactory::new()->asAdmin()->create(['email' => 'admin-fallback@example.test']);

        self::getContainer()->get(NotificationPublisherInterface::class)->publish(new NotificationRequest(
            type: 'admin.user_registered',
            recipientUserIds: [(int) $recipient->getId()],
            deduplicationKey: 'admin.user_registered:999999',
            parameters: ['userId' => 999999, 'username' => 'gone-soon'],
        ));

        $email = $this->emailTo('admin-fallback@example.test');
        self::assertStringContainsString('gone-soon', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('grant-participant', (string) $email->getHtmlBody(), 'the generic notification email is sent');
    }

    public function testNoRecipientsMeansNoNotification(): void
    {
        UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $user = UserFactory::createOne();

        $this->dispatch((int) $user->getId());
        $this->dispatch(999999);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification'));
        self::assertEmailCount(0);
    }

    /**
     * @return list<array<string, mixed>> the notification rows by recipient, JSON columns decoded
     */
    private function notifications(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT recipient_id, type, category, access_scope, required_role, action_route, action_parameters, parameters FROM notification ORDER BY recipient_id',
        );

        return array_map(static function (array $row): array {
            $row['action_parameters'] = json_decode((string) $row['action_parameters'], true);
            $row['parameters'] = json_decode((string) $row['parameters'], true);

            return $row;
        }, $rows);
    }

    private function dispatch(int $userId): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new UserRegistered($userId));
    }

    private function emailTo(string $address): TemplatedEmail
    {
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof TemplatedEmail && \in_array($address, array_map(static fn (Address $to): string => $to->getAddress(), $message->getTo()), true)) {
                return $message;
            }
        }

        self::fail(\sprintf('No email to %s.', $address));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
