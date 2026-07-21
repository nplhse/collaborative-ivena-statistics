<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail;

use App\Shared\Infrastructure\Mail\MailConfig;
use App\Shared\Infrastructure\Mail\SymfonyTransactionalMailer;
use PHPUnit\Framework\TestCase;
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
                self::assertSame('Passwort zurücksetzen', $email->getSubject());
                self::assertSame('de', $email->getLocale());
                self::assertSame('@User/reset_password/email.html.twig', $email->getHtmlTemplate());
                self::assertSame(
                    'https://example.test/reset-password/reset/selector_verifier',
                    $email->getContext()['resetUrl'] ?? null,
                );

                return true;
            }));

        $this->createMailer($mailer, urlGenerator: $urlGenerator, translator: $translator)->sendPasswordResetEmail(
            'reset@example.test',
            $resetToken,
            'de',
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
            $this->createMock(UrlGeneratorInterface::class),
            $this->createTranslatorMock(),
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
        ?UrlGeneratorInterface $urlGenerator = null,
        ?TranslatorInterface $translator = null,
    ): SymfonyTransactionalMailer {
        return new SymfonyTransactionalMailer(
            $mailer,
            new MailConfig(
                fromEmail: 'no-reply@example.test',
                fromName: 'Test App',
                appName: 'Test App',
                replyTo: '',
            ),
            $urlGenerator ?? $this->createMock(UrlGeneratorInterface::class),
            $translator ?? $this->createTranslatorMock(),
        );
    }

    private function createTranslatorMock(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => match ($id) {
                'email.verify.title' => 'de' === $locale ? 'E-Mail-Adresse bestätigen' : 'Confirm your email address',
                'email.reset_password.title' => 'de' === $locale ? 'Passwort zurücksetzen' : 'Reset your password',
                default => $id,
            },
        );

        return $translator;
    }
}
