<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\Shared\Infrastructure\Mail\SymfonyTransactionalMailer;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\FeedbackRecipientEmailResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

final class SymfonyTransactionalMailerTest extends TestCase
{
    public function testSendVerificationEmailUsesConfiguredSenderRecipientAndLocale(): void
    {
        $translator = $this->createTranslatorMock();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame('no-reply@example.test', $email->getFrom()[0]->getAddress());
                self::assertSame('Test App', $email->getFrom()[0]->getName());
                self::assertSame(['user@example.test'], array_map(
                    static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame('E-Mail-Adresse bestätigen', $email->getSubject());
                self::assertSame('de', $email->getLocale());
                self::assertSame('@User/registration/confirmation_email.html.twig', $email->getHtmlTemplate());

                return true;
            }));

        $this->createMailer($mailer, translator: $translator)->sendVerificationEmail(
            'user@example.test',
            'https://example.test/verify',
            'key',
            ['%count%' => 1],
            'https://example.test/',
            'de',
        );
    }

    public function testSendPasswordResetEmailUsesConfiguredSenderAbsoluteResetUrlAndLocale(): void
    {
        $resetToken = new ResetPasswordToken(
            'selector_verifier',
            new \DateTimeImmutable('+1 hour'),
            time(),
        );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'app_reset_password',
                ['token' => 'selector_verifier'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.test/reset-password/reset/selector_verifier');

        $translator = $this->createTranslatorMock();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame('no-reply@example.test', $email->getFrom()[0]->getAddress());
                self::assertSame(['reset@example.test'], array_map(
                    static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame('Reset your password', $email->getSubject());
                self::assertSame('en', $email->getLocale());
                self::assertSame('@User/reset_password/email.html.twig', $email->getHtmlTemplate());
                self::assertInstanceOf(ResetPasswordToken::class, $email->getContext()['resetToken'] ?? null);
                self::assertSame(
                    'https://example.test/reset-password/reset/selector_verifier',
                    $email->getContext()['resetUrl'] ?? null,
                );

                return true;
            }));

        $this->createMailer($mailer, urlGenerator: $urlGenerator, translator: $translator)->sendPasswordResetEmail(
            'reset@example.test',
            $resetToken,
            'en',
        );
    }

    public function testSendAdminFeedbackEmailUsesAllResolvedRecipientsGroupedByLocale(): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('Broken filter')
            ->setPageUrl('https://example.test/page');

        $adminA = $this->createAdminUser('admin-a@example.test', 'de');
        $adminB = $this->createAdminUser('admin-b@example.test', 'de');

        $recipientResolver = $this->createMock(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientUsers')->willReturn([$adminA, $adminB]);

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

        $this->createMailer($mailer, $recipientResolver, translator: $translator)->sendAdminFeedbackEmail(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    public function testSendAdminFeedbackEmailSendsSeparateMailsPerLocale(): void
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

        $this->createMailer($mailer, $recipientResolver, translator: $translator, localeResolver: $localeResolver)->sendAdminFeedbackEmail(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    public function testSendAdminFeedbackEmailSkipsWhenNoRecipients(): void
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

        $this->createMailer($mailer, $recipientResolver, $logger)->sendAdminFeedbackEmail(
            $feedback,
            FeedbackCategory::BUG,
            '{}',
        );
    }

    public function testReplyToIsAppliedWhenConfigured(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(['support@example.test'], array_map(
                    static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
                    $email->getReplyTo(),
                ));

                return true;
            }));

        $mailConfig = new MailConfig(
            fromEmail: 'no-reply@example.test',
            fromName: 'Test App',
            appName: 'Test App',
            replyTo: 'support@example.test',
        );

        new SymfonyTransactionalMailer(
            $mailer,
            $mailConfig,
            $this->createMock(FeedbackRecipientEmailResolver::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createTranslatorMock(),
            new LocaleResolver(new LocaleCookieManager()),
            $this->createMock(LoggerInterface::class),
        )->sendVerificationEmail(
            'user@example.test',
            'https://example.test/verify',
            'key',
            [],
            'https://example.test/',
            'en',
        );
    }

    private function createMailer(
        MailerInterface $mailer,
        ?FeedbackRecipientEmailResolver $recipientResolver = null,
        ?LoggerInterface $logger = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?TranslatorInterface $translator = null,
        ?LocaleResolver $localeResolver = null,
    ): SymfonyTransactionalMailer {
        return new SymfonyTransactionalMailer(
            $mailer,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'Test App',
                appName: 'Test App',
                replyTo: '',
            ),
            $recipientResolver ?? $this->createMock(FeedbackRecipientEmailResolver::class),
            $urlGenerator ?? $this->createMock(UrlGeneratorInterface::class),
            $translator ?? $this->createTranslatorMock(),
            $localeResolver ?? new LocaleResolver(new LocaleCookieManager()),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    private function createTranslatorMock(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => match ($id) {
                'email.verify.title' => 'de' === $locale ? 'E-Mail-Adresse bestätigen' : 'Confirm your email address',
                'email.reset_password.title' => 'de' === $locale ? 'Passwort zurücksetzen' : 'Reset your password',
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
