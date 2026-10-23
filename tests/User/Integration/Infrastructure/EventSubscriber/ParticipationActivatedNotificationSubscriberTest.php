<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\EventSubscriber;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Event\UserBecameParticipant;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\EventSubscriber\ParticipationActivatedNotificationSubscriber;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ParticipationActivatedNotificationSubscriberTest extends DatabaseKernelTestCase
{
    public function testActivationPutsOneEntryIntoTheInbox(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new UserBecameParticipant((int) $user->getId()));

        $row = $this->connection()->fetchAssociative('SELECT type, category, action_route FROM notification WHERE recipient_id = ?', [$user->getId()]);
        self::assertSame(['type' => 'participation.activated', 'category' => 'account', 'action_route' => 'app_default'], $row);
    }

    public function testActivationSendsTheWelcomeMailThroughTheNotificationEmail(): void
    {
        $user = UserFactory::createOne(['username' => 'welcome-me', 'email' => 'welcome-me@example.test', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new UserBecameParticipant((int) $user->getId()));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(['welcome-me@example.test'], array_map(static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertStringContainsString('welcome-me', (string) $email->getHtmlBody());
        self::assertSame('sent', $this->connection()->fetchOne(
            'SELECT d.status FROM notification_delivery d JOIN notification n ON n.id = d.notification_id WHERE n.recipient_id = ?',
            [$user->getId()],
        ), 'the welcome mail is the mandatory email of participation.activated');
    }

    public function testEveryActivationIsItsOwnNotification(): void
    {
        $user = UserFactory::createOne();
        $clock = new MockClock('2026-10-22 10:00:00');
        $subscriber = new ParticipationActivatedNotificationSubscriber(
            self::getContainer()->get(NotificationPublisherInterface::class),
            $clock,
        );
        $event = new UserBecameParticipant((int) $user->getId());

        $subscriber->onUserBecameParticipant($event);
        $subscriber->onUserBecameParticipant($event);
        $clock->modify('+3 days');
        $subscriber->onUserBecameParticipant($event);

        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$user->getId()]));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
