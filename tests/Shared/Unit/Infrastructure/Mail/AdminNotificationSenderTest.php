<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail;

use App\Shared\Application\Notification\AdminNotification;
use App\Shared\Application\Notification\AdminNotificationType;
use App\Shared\Infrastructure\Mail\AdminNotificationSender;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Infrastructure\Security\NotificationRecipientEmailResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminNotificationSenderTest extends TestCase
{
    public function testSendUsesResolvedRecipientsAndTemplate(): void
    {
        $recipientResolver = $this->createMock(NotificationRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientEmails')->willReturn([
            'admin-a@example.test',
            'admin-b@example.test',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('New user registration');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(
                    ['admin-a@example.test', 'admin-b@example.test'],
                    array_map(static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(), $email->getTo()),
                );
                self::assertSame('[Test App] New user registration', $email->getSubject());
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
        $recipientResolver = $this->createMock(NotificationRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientEmails')->willReturn(['admin@example.test']);

        $translator = $this->createMock(TranslatorInterface::class);
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
        $recipientResolver = $this->createMock(NotificationRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientEmails')->willReturn([]);

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
            $translator ?? $this->createMock(TranslatorInterface::class),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }
}
