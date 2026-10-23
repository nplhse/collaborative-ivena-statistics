<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\EventSubscriber;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Address;

final class UserRegisteredNotificationSubscriberTest extends DatabaseKernelTestCase
{
    public function testRegistrationNotifiesEachSystemRecipientOnce(): void
    {
        $recipient = UserFactory::new()->asNotificationRecipient()->create(['email' => 'admin-notify@example.test']);
        $otherRecipient = UserFactory::new()->asNotificationRecipient()->create();
        $adminWithoutOptIn = UserFactory::new()->asAdmin()->create();
        $disabledRecipient = UserFactory::new()->asNotificationRecipient()->create(['isEnabled' => false]);
        $user = UserFactory::createOne(['username' => 'notify-me', 'email' => 'notify-me@example.test']);

        $this->dispatch((int) $user->getId());
        $this->dispatch((int) $user->getId());

        foreach ([$recipient, $otherRecipient] as $admin) {
            $rows = $this->connection()->fetchAllAssociative('SELECT * FROM notification WHERE recipient_id = ?', [$admin->getId()]);
            self::assertCount(1, $rows, 'the same registration notifies every recipient once');
            self::assertSame('admin.user_registered', $rows[0]['type']);
            self::assertSame('administration', $rows[0]['category']);
            self::assertSame('role', $rows[0]['access_scope']);
            self::assertSame('ROLE_ADMIN', $rows[0]['required_role']);
            self::assertSame('app_admin_dashboard_user_detail', $rows[0]['action_route']);
            self::assertSame(['entityId' => $user->getId()], json_decode((string) $rows[0]['action_parameters'], true));
            self::assertSame(['userId' => $user->getId(), 'username' => 'notify-me'], json_decode((string) $rows[0]['parameters'], true), 'no email address is stored');
        }

        foreach ([$adminWithoutOptIn, $disabledRecipient, $user] as $other) {
            self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$other->getId()]));
        }

        self::assertEmailCount(2);
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
        $recipient = UserFactory::new()->asNotificationRecipient()->create(['email' => 'admin-fallback@example.test']);

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
        UserFactory::new()->asAdmin()->create();
        $user = UserFactory::createOne();

        $this->dispatch((int) $user->getId());
        $this->dispatch(999999);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification'));
        self::assertEmailCount(0);
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
