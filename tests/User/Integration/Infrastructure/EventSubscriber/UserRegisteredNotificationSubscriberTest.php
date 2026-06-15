<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\EventSubscriber;

use App\Shared\Application\Notification\AdminNotification;
use App\Shared\Application\Notification\AdminNotificationSenderInterface;
use App\Shared\Application\Notification\AdminNotificationType;
use App\User\Application\Event\UserRegistered;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\EventSubscriber\UserRegisteredNotificationSubscriber;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class UserRegisteredNotificationSubscriberTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testOnUserRegisteredSendsNotification(): void
    {
        $user = UserFactory::createOne([
            'username' => 'notify-me',
            'email' => 'notify-me@example.test',
        ]);
        $userId = (int) $user->getId();

        $sender = $this->createMock(AdminNotificationSenderInterface::class);
        $sender->expects(self::once())
            ->method('send')
            ->with(self::callback(function (AdminNotification $notification): bool {
                self::assertSame(AdminNotificationType::UserRegistered, $notification->type);
                self::assertSame('notify-me', $notification->templateContext['username'] ?? null);
                self::assertSame('notify-me@example.test', $notification->templateContext['userEmail'] ?? null);
                self::assertIsString($notification->templateContext['userManagementUrl'] ?? null);
                self::assertIsString($notification->templateContext['grantParticipantUrl'] ?? null);

                return true;
            }));

        $subscriber = new UserRegisteredNotificationSubscriber(
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(\App\Admin\Application\Service\AdminUserUrlGenerator::class),
            self::getContainer()->get(\App\Admin\Application\Service\GrantParticipantUrlGenerator::class),
            $sender,
        );

        $subscriber->onUserRegistered(new UserRegistered($userId));
    }
}
