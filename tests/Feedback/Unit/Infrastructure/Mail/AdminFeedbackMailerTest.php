<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Infrastructure\Mail;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Infrastructure\Mail\AdminFeedbackMailer;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\FeedbackRecipientEmailResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminFeedbackMailerTest extends TestCase
{
    public function testNotifySendsLocalizedAdminFeedbackMail(): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('Broken filter')
            ->setPageUrl('https://example.test/page');

        $recipientResolver = $this->createMock(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([
            $this->createAdminUser('admin-a@example.test', 'de'),
            $this->createAdminUser('admin-b@example.test', 'de'),
        ]);

        $translator = $this->createTranslatorMock();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(
                    ['admin-a@example.test', 'admin-b@example.test'],
                    array_map(static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(), $email->getTo()),
                );
                self::assertSame('[Test App] Feedback (bug)', $email->getSubject());
                self::assertSame('de', $email->getLocale());
                self::assertSame('@Feedback/email/admin_feedback_notification.html.twig', $email->getHtmlTemplate());

                return true;
            }));

        $this->createMailer($mailer, $recipientResolver, translator: $translator)->notify(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    public function testNotifySendsSeparateMailsPerLocale(): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('Broken filter')
            ->setPageUrl('https://example.test/page');

        $recipientResolver = $this->createMock(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([
            $this->createAdminUser('de-admin@example.test', 'de'),
            $this->createAdminUser('en-admin@example.test', 'en'),
        ]);

        $localeResolver = new LocaleResolver(new LocaleCookieManager());

        $translator = $this->createTranslatorMock();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                $locale = $email->getLocale();
                self::assertContains($locale, ['de', 'en']);

                return true;
            }));

        $this->createMailer($mailer, $recipientResolver, translator: $translator, localeResolver: $localeResolver)->notify(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    public function testNotifySkipsWhenNoRecipients(): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('Broken filter')
            ->setPageUrl('https://example.test/page');

        $recipientResolver = $this->createMock(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'feedback.admin_mail_skipped',
                self::callback(static fn (array $context): bool => 'no_feedback_recipients' === ($context['reason'] ?? null)),
            );

        $this->createMailer($mailer, $recipientResolver, $logger)->notify(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    private function createMailer(
        MailerInterface $mailer,
        ?FeedbackRecipientEmailResolver $recipientResolver = null,
        ?LoggerInterface $logger = null,
        ?TranslatorInterface $translator = null,
        ?LocaleResolver $localeResolver = null,
    ): AdminFeedbackMailer {
        return new AdminFeedbackMailer(
            $mailer,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'Test App',
                appName: 'Test App',
                replyTo: '',
            ),
            $recipientResolver ?? $this->createMock(FeedbackRecipientEmailResolver::class),
            $translator ?? $this->createTranslatorMock(),
            $localeResolver ?? new LocaleResolver(new LocaleCookieManager()),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    private function createTranslatorMock(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'feedback.email.title' => 'Feedback',
                default => $id,
            },
        );

        return $translator;
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
