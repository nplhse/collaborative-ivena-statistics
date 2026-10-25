<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Delivery;

use App\Notification\Application\Inbox\InboxEntry;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Infrastructure\Delivery\NotificationEmailFactory;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\Tests\Support\Notification\Type\EmailTestNotification;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class NotificationEmailFactoryTest extends KernelTestCase
{
    use Factories;

    public function testEntriesWithoutUsableRouteGetNoLinkAndTheReplyToIsSet(): void
    {
        $container = self::getContainer();
        $factory = new NotificationEmailFactory(
            $container->get(TranslatorInterface::class),
            $container->get(UrlGeneratorInterface::class),
            new MailConfig('no-reply@example.test', 'Test App', 'Test App', 'support@example.test'),
            $container->get(LocaleResolver::class),
            [],
        );
        $user = UserFactory::createOne();

        $withoutRoute = $factory->create(1, 'test.email', $this->entry(null), $user);
        $unknownRoute = $factory->create(2, 'test.email', $this->entry('app_does_not_exist'), $user);

        self::assertNull($withoutRoute->getContext()['actionUrl']);
        self::assertNull($unknownRoute->getContext()['actionUrl']);
        self::assertSame('support@example.test', $withoutRoute->getReplyTo()[0]->getAddress());
        self::assertSame('Test App', $withoutRoute->getFrom()[0]->getName());
    }

    public function testNotificationWithoutPreparedEmailLeavesTheMessageToNotifier(): void
    {
        self::assertNull(new EmailTestNotification('Subject')->asEmailMessage(new Recipient('reader@example.test')));
    }

    private function entry(?string $route): InboxEntry
    {
        return new InboxEntry(
            id: 1,
            translationDomain: 'notification',
            titleKey: 'notification.title',
            messageKey: 'notification.title',
            parameters: [],
            actionRoute: $route,
            actionParameters: [],
            accessScope: NotificationAccessScope::None,
            scopeReferenceId: null,
            requiredRole: null,
            createdAt: new \DateTimeImmutable(),
            readAt: null,
        );
    }
}
