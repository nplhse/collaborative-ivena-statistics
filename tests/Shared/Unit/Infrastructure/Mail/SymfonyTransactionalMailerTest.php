<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Shared\Infrastructure\Mail\MailConfig;
use App\Shared\Infrastructure\Mail\SymfonyTransactionalMailer;
use App\User\Infrastructure\Security\FeedbackRecipientEmailResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

final class SymfonyTransactionalMailerTest extends TestCase
{
    public function testSendVerificationEmailUsesConfiguredSenderAndRecipient(): void
    {
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
                self::assertSame('Please confirm your email address', $email->getSubject());
                self::assertSame('@User/registration/confirmation_email.html.twig', $email->getHtmlTemplate());

                return true;
            }));

        $this->createMailer($mailer)->sendVerificationEmail(
            'user@example.test',
            'https://example.test/verify',
            'key',
            ['%count%' => 1],
            'https://example.test/',
        );
    }

    public function testSendPasswordResetEmailUsesConfiguredSenderAndAbsoluteResetUrl(): void
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

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame('no-reply@example.test', $email->getFrom()[0]->getAddress());
                self::assertSame(['reset@example.test'], array_map(
                    static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame('Your password reset request', $email->getSubject());
                self::assertSame('@User/reset_password/email.html.twig', $email->getHtmlTemplate());
                self::assertInstanceOf(ResetPasswordToken::class, $email->getContext()['resetToken'] ?? null);
                self::assertSame(
                    'https://example.test/reset-password/reset/selector_verifier',
                    $email->getContext()['resetUrl'] ?? null,
                );

                return true;
            }));

        $this->createMailer($mailer, urlGenerator: $urlGenerator)->sendPasswordResetEmail('reset@example.test', $resetToken);
    }

    public function testSendAdminFeedbackEmailUsesAllResolvedRecipients(): void
    {
        $feedback = new Feedback()
            ->setCategory(FeedbackCategory::BUG)
            ->setMessage('Broken filter')
            ->setPageUrl('https://example.test/page');

        $recipientResolver = $this->createMock(FeedbackRecipientEmailResolver::class);
        $recipientResolver->method('resolveRecipientEmails')->willReturn([
            'admin-a@example.test',
            'admin-b@example.test',
        ]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame(
                    ['admin-a@example.test', 'admin-b@example.test'],
                    array_map(static fn (\Symfony\Component\Mime\Address $address): string => $address->getAddress(), $email->getTo()),
                );
                self::assertSame('[Test App] Feedback (bug)', $email->getSubject());
                self::assertSame('@Feedback/email/admin_feedback_notification.html.twig', $email->getHtmlTemplate());

                return true;
            }));

        $this->createMailer($mailer, $recipientResolver)->sendAdminFeedbackEmail(
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
        $recipientResolver->method('resolveRecipientEmails')->willReturn([]);

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
            $this->createMock(LoggerInterface::class),
        )->sendVerificationEmail(
            'user@example.test',
            'https://example.test/verify',
            'key',
            [],
            'https://example.test/',
        );
    }

    private function createMailer(
        MailerInterface $mailer,
        ?FeedbackRecipientEmailResolver $recipientResolver = null,
        ?LoggerInterface $logger = null,
        ?UrlGeneratorInterface $urlGenerator = null,
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
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }
}
