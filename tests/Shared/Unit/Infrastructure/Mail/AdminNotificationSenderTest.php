<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail;

use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Application\Notification\AdminNotification;
use App\Shared\Application\Notification\AdminNotificationType;
use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\Shared\Infrastructure\Mail\AdminNotificationSender;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\NotificationRecipientEmailResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminNotificationSenderTest extends TestCase
{
    public function testSendUsesResolvedRecipientsTemplateAndLocale(): void
    {
        $adminA = $this->createAdminUser('admin-a@example.test', 'de');
        $adminB = $this->createAdminUser('admin-b@example.test', 'de');

        $recipientResolver = $this->createStub(NotificationRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([$adminA, $adminB]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Neue Benutzerregistrierung');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(
                    ['admin-a@example.test', 'admin-b@example.test'],
                    array_map(static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(), $email->getTo()),
                );
                self::assertSame('[Test App] Neue Benutzerregistrierung', $email->getSubject());
                self::assertSame('de', $email->getLocale());
                self::assertSame('@Shared/email/admin_notification/user_registered.html.twig', $email->getHtmlTemplate());
                self::assertSame('new-user', $email->getContext()['username'] ?? null);

                return true;
            }));

        $this->createSender($mailer, $recipientResolver, $translator)->send(new AdminNotification(
            type: AdminNotificationType::UserRegistered,
            templateContext: ['username' => 'new-user'],
        ));
    }

    public function testSendUsesImportFailedTemplate(): void
    {
        $recipientResolver = $this->createStub(NotificationRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([
            $this->createAdminUser('admin@example.test', 'en'),
        ]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Import failed');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(['admin@example.test'], array_map(
                    static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame('[Test App] Import failed', $email->getSubject());
                self::assertSame('en', $email->getLocale());
                self::assertSame('@Shared/email/admin_notification/import_failed.html.twig', $email->getHtmlTemplate());
                self::assertSame('Broken import', $email->getContext()['importName'] ?? null);
                self::assertSame('Missing header row', $email->getContext()['reason'] ?? null);

                return true;
            }));

        $this->createSender($mailer, $recipientResolver, $translator)->send(new AdminNotification(
            type: AdminNotificationType::ImportFailed,
            templateContext: [
                'importName' => 'Broken import',
                'reason' => 'Missing header row',
            ],
            referenceId: '99',
        ));
    }

    public function testSendSkipsWhenNoRecipients(): void
    {
        $recipientResolver = $this->createStub(NotificationRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'admin_notification.skipped',
                self::callback(static fn (array $context): bool => 'no_notification_recipients' === ($context['reason'] ?? null)),
            );

        $this->createSender($mailer, $recipientResolver, logger: $logger)->send(new AdminNotification(
            type: AdminNotificationType::ImportFailed,
            templateContext: ['importName' => 'Broken import'],
            referenceId: '42',
        ));
    }

    private function createSender(
        MailerInterface $mailer,
        NotificationRecipientEmailResolver $recipientResolver,
        ?TranslatorInterface $translator = null,
        ?LoggerInterface $logger = null,
    ): AdminNotificationSender {
        return new AdminNotificationSender(
            $mailer,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'Test App',
                appName: 'Test App',
                replyTo: '',
            ),
            $recipientResolver,
            $translator ?? $this->createStub(TranslatorInterface::class),
            new LocaleResolver(new LocaleCookieManager()),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function createAdminUser(string $email, string $locale): User
    {
        $user = new User();
        $user->setUsername(str_replace('@', '-', $email));
        $user->setEmail($email);
        $user->setPassword('hashed');
        $user->setLocale($locale);

        return $user;
    }
}
